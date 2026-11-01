<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Http\Requests\Server\StoreCloudServerRequest;
use App\Jobs\RunCloudTask;
use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Server\ProvisioningLog;
use App\Services\SSH\KeyPairGenerator;
use App\Services\Teams\TeamContext;
use Illuminate\Http\RedirectResponse;

final class CloudServerController extends Controller
{
    public function store(StoreCloudServerRequest $request, KeyPairGenerator $keys, TeamContext $context): RedirectResponse
    {
        $team = $context->teamOrFail();
        $account = $request->account();
        $size = $request->size();
        $image = $request->cloudImage();
        $region = (string) $request->validated('region');
        $name = (string) $request->validated('name');
        $label = $account->type->label();
        $price = $size->priceIn($region);
        $pair = $keys->generate('coresmith@' . $name);

        $server = Server::query()->create([
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
        $this->authorize('update', $server);

        $task = CloudTask::of($server);

        abort_unless($task !== null && $task->type === CloudTask::CREATE && $task->state === CloudTask::FAILED, 404);

        CloudTask::resume($server);
        RunCloudTask::dispatch($server);

        return back()->with('success', 'Trying again. This page follows along.');
    }
}
