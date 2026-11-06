<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Exceptions\CloudProviderException;
use App\Http\Requests\Server\DestroyCloudServerRequest;
use App\Http\Requests\Server\ResizeCloudServerRequest;
use App\Http\Requests\Server\StoreCloudServerRequest;
use App\Jobs\RunCloudTask;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Billing\PlanGate;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\ResizeOptions;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CreateServerTask;
use App\Services\Panel\CloudPresenter;
use App\Services\Panel\Formats;
use App\Services\Server\ProvisioningLog;
use App\Services\SSH\KeyPairGenerator;
use App\Services\Teams\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class CloudServerController extends Controller
{
    public function store(StoreCloudServerRequest $request, KeyPairGenerator $keys, TeamContext $context, PlanGate $plans): RedirectResponse
    {
        $team = $context->teamOrFail();
        $account = $request->account();
        $size = $request->size();
        $image = $request->cloudImage();
        $region = (string) $request->validated('region');
        $name = (string) $request->validated('name');
        $label = $account->type->label();
        $price = $size->priceIn($region);

        $server = $plans->creatingServer($team, $request->user(), function () use ($team, $name, $request, $region, $account, $size, $image, $price, $keys): Server {
            $pair = $keys->generate('coresmith@' . $name);

            return Server::query()->create([
                'team_id' => $team->getKey(),
                'name' => $name,
                'environment' => $request->validated('environment'),
                'region' => $region,
                'ip_address' => null,
                'ssh_port' => 22,
                'ssh_user' => 'root',
                'ssh_private_key' => $pair->privateKey,
                'ssh_public_key' => $pair->publicKey,
                'status' => ServerStatus::Creating,
                'cloud_provider_id' => $account->getKey(),
                'provider_size' => $size->id,
                'provider_image' => $image->id,
                'provider_monthly_cost' => $price?->monthly,
                'provider_currency' => $price?->currency,
            ]);
        });

        CloudTask::start($server, CloudTask::CREATE, 'create', ['image_label' => $image->label, 'region_label' => $request->regionLabel()], $request->user());
        ProvisioningLog::append($server, "creating {$name} at {$label}, {$size->id} in {$region} with {$image->label}");

        activity()
            ->performedOn($server)
            ->withProperties(['provider' => $label, 'size' => $size->id, 'region' => $region])
            ->log('server_creation_started');

        RunCloudTask::dispatch($server);

        $message = "Creating {$name} at {$label}. This page follows along, close it and it keeps going.";

        return $request->validated('return_to') === 'setup'
            ? to_route('setup.show', ['step' => 2])->with('success', $message)
            : to_route('servers.show', $server)->with('success', $message);
    }

    public function retry(Server $server): RedirectResponse
    {
        $task = CloudTask::of($server);

        abort_unless($task !== null && $task->state === CloudTask::FAILED && in_array($task->type, [CloudTask::CREATE, CloudTask::DESTROY], true), 404);

        $this->authorize($task->type === CloudTask::DESTROY ? 'delete' : 'update', $server);

        CloudTask::resume($server);
        RunCloudTask::dispatch($server);

        return back()->with('success', 'Trying again. This page follows along.');
    }

    public function destroy(DestroyCloudServerRequest $request, Server $server): RedirectResponse
    {
        $account = $server->cloudProvider;

        abort_if($account === null || (blank($server->provider_server_id) && !CreateServerTask::uncertain($server)), 404);

        $label = $account->type->label();

        if (CloudTask::running($server)) {
            return back()->with('error', "Coresmith is already working on {$server->name}. Wait for that to finish first.");
        }

        if ($server->status === ServerStatus::Provisioning) {
            return back()->with('error', "Coresmith is provisioning {$server->name}. Destroy it once provisioning finishes or fails.");
        }

        if ($this->deploying($server)) {
            return back()->with('error', "A deploy is running on {$server->name}. Destroy it once the deploy finishes.");
        }

        $keyId = CloudTask::of($server)?->data['key_id'] ?? null;

        CloudTask::start($server, CloudTask::DESTROY, 'delete', is_string($keyId) && $keyId !== '' ? ['key_id' => $keyId] : [], $request->user());
        ProvisioningLog::append($server, "destroying {$server->name} at {$label}");
        RunCloudTask::dispatch($server);

        return back()->with('success', "Destroying {$server->name} at {$label}. It leaves the fleet once {$label} confirms.");
    }

    public function sizes(Server $server, ResizeOptions $options): JsonResponse
    {
        $this->authorize('update', $server);

        abort_unless(ResizeOptions::resizable($server), 404);

        $account = $server->cloudProvider;
        $label = $account->type->label();

        if ($account->token_failed_at !== null) {
            return response()->json(['error' => CloudPresenter::refusal($account)]);
        }

        try {
            [$current, $sizes] = $options->for($server);
        } catch (CloudProviderException $e) {
            return response()->json(['error' => "Coresmith couldn't load {$label}'s regions and sizes. {$e->getMessage()}"]);
        }

        if ($current === null) {
            return response()->json(['error' => "Coresmith can't find {$server->provider_size} in {$label}'s current sizes, so it can't offer a resize."]);
        }

        $region = (string) $server->region;
        $monthly = fn(CloudSize $size): ?string => ($price = $size->priceIn($region)) === null ? null : Formats::money($price->monthly, $price->currency);

        return response()->json([
            'current' => ['id' => $current->id, 'label' => $current->label(), 'disk_gb' => $current->diskGb, 'monthly' => $monthly($current)],
            'sizes' => $sizes->map(fn(CloudSize $size): array => ['id' => $size->id, 'label' => $size->label(), 'monthly' => $monthly($size)])->values()->all(),
            'note' => ResizeOptions::upOnly($server)
                ? "{$label} only resizes up, and the disk grows with the new size."
                : "The disk stays {$current->diskGb} GB, so you can size back down later.",
            'error' => null,
        ]);
    }

    public function resize(ResizeCloudServerRequest $request, Server $server, CloudApiFactory $apis): RedirectResponse
    {
        $refusal = CloudTask::refusal($server, 'Resize');

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        if ($this->deploying($server)) {
            return back()->with('error', "A deploy is running on {$server->name}. Resize it once the deploy finishes.");
        }

        $account = $server->cloudProvider;
        $from = (string) $server->provider_size;
        $to = $request->size();
        $cycle = $apis->for($account)->resizeNeedsPowerOff();

        CloudTask::start($server, CloudTask::RESIZE, $cycle ? 'power_off' : 'change', ['from' => $from, 'to' => $to, 'power_cycle' => $cycle], $request->user());
        ProvisioningLog::append($server, "resizing {$server->name} from {$from} to {$to} at {$account->type->label()}");
        RunCloudTask::dispatch($server);

        return back()->with('success', "Resizing {$server->name} to {$to}. Apps are offline until it starts again.");
    }

    public function dismiss(Server $server): RedirectResponse
    {
        $task = CloudTask::of($server);

        abort_unless($task !== null && $task->type === CloudTask::RESIZE && $task->state === CloudTask::FAILED, 404);

        $this->authorize('update', $server);

        CloudTask::clear($server);

        return back()->with('success', 'Dismissed.');
    }

    private function deploying(Server $server): bool
    {
        return Deployment::query()
            ->whereIn('application_id', $server->applications()->select('id'))
            ->where('status', DeploymentStatus::Running->value)
            ->exists();
    }
}
