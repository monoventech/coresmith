<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Exceptions\ServerConnectionFailed;
use App\Exceptions\ThisMachineRefused;
use App\Http\Requests\Server\ConnectServerRequest;
use App\Http\Requests\Server\StoreServerRequest;
use App\Http\Requests\Server\UpdateServerRequest;
use App\Jobs\AuditServer;
use App\Jobs\FixAuditFinding;
use App\Jobs\ProvisionServer;
use App\Jobs\PruneReleases;
use App\Jobs\RebootServer;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CreateServerTask;
use App\Services\Deployment\ReleasePaths;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Panel\CloudPresenter;
use App\Services\Panel\Formats;
use App\Services\Panel\ServerPresenter;
use App\Services\Server\Audit\PreProvisioningAudit;
use App\Services\Server\Audit\ServerAudit;
use App\Services\Server\CoresmithKey;
use App\Services\Server\ServerConnector;
use App\Services\Server\ThisMachine;
use App\Services\SSH\KeyPairGenerator;
use App\Services\Teams\TeamAccess;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamUrls;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ServerController extends Controller
{
    public const ENVIRONMENTS = ['Production', 'Staging', 'Development'];

    public const PICK_ENVIRONMENT = 'Pick Production, Staging, Development or No environment.';

    public function create(Request $request, CloudPresenter $cloud, TeamContext $context, PlanGate $plans, ThisMachine $machine): Response
    {
        $this->authorize('create', Server::class);

        $team = $context->teamOrFail();
        $requested = $request->query('account');
        $requested = is_string($requested) ? $requested : null;

        return Inertia::render('servers/Create', [
            'teams' => $request->user()->teams()->get()
                ->map(fn(Team $choice): array => ['id' => $choice->id, 'name' => $choice->name, 'limit' => $plans->serverLimitNote($choice, $request->user())])->values(),
            'current' => $team->getKey(),
            'environments' => self::ENVIRONMENTS,
            'phases' => ServerPresenter::PROVISIONING_PHASES,
            'thisMachine' => $request->user()->managesInstance() ? [
                'installed' => $machine->installed(),
                'taken_by' => $machine->server()?->team?->name,
                'name' => (string) gethostname(),
                'installer' => ThisMachine::INSTALLER,
            ] : null,
            'cloud' => fn(): array => $cloud->choices($team, $request->user(), $requested),
            'catalog' => Inertia::defer(function () use ($cloud, $team, $requested, $request): ?array {
                if (!$request->user()->hasTeamAbility($team, Ability::CreateCloudServer)) {
                    return null;
                }

                $account = $cloud->selectedAccount($team, $requested);

                return $account === null ? null : $cloud->catalog($account);
            }),
        ]);
    }

    public function store(StoreServerRequest $request, KeyPairGenerator $keys, PlanGate $plans, TeamContext $context, TeamUrls $urls, ServerConnector $connector, TeamAccess $access): HttpResponse
    {
        $team = Team::query()->findOrFail($request->validated('team_id'));

        $server = $plans->creatingServer($team, $request->user(), function () use ($request, $keys): Server {
            $pair = $keys->generate('coresmith@' . (Str::slug((string) $request->validated('name')) ?: 'server'));

            return Server::create([
                ...$request->safe()->except(['password']),
                'ssh_private_key' => $pair->privateKey,
                'ssh_public_key' => $pair->publicKey,
                'status' => ServerStatus::Pending,
            ]);
        });

        activity()->performedOn($server)->log('server_added');
        $access->grant($team, $request->user(), $server);

        [$tone, $added] = $this->added($server, $connector, $request->safe()->string('password')->value());

        if ($context->explicit() && $context->team()?->isNot($team)) {
            $request->session()->flash($tone, $added);

            return Inertia::location($urls->pathUrl($team, $request) . route('servers.show', $server, false));
        }

        return to_route('servers.show', $server)->with($tone, $added);
    }

    public function show(Request $request, Server $server, ServerPresenter $presenter, TeamAccess $access): Response|RedirectResponse
    {
        if ($server->trashed()) {
            return $this->destroyedServer($server);
        }

        $this->authorize('view', $server);

        $running = Deployment::query()
            ->whereIn('application_id', $server->applications()->select('id'))
            ->where('status', DeploymentStatus::Running->value)
            ->get()
            ->keyBy('application_id');
        $deployed = Deployment::query()
            ->whereIn('application_id', $server->applications()->select('id'))
            ->where('status', DeploymentStatus::Succeeded->value)
            ->groupBy('application_id')
            ->selectRaw('application_id, max(finished_at) as finished_at')
            ->pluck('finished_at', 'application_id');

        return Inertia::render('servers/Show', [
            'server' => $presenter->header($server, $request->user()),
            'applications' => $access->applicationsOn($request->user(), $server)->orderBy('name')->get()->map(fn(Application $application): array => [
                'id' => $application->id,
                'name' => $application->name,
                'kind' => ApplicationPresenter::kind($application),
                'runtime' => ApplicationPresenter::runtime($application),
                'domain' => $application->domain,
                'deployed' => $deployed->has($application->id) ? Formats::when(CarbonImmutable::parse($deployed->get($application->id))) : null,
                'state' => $running->has($application->id)
                    ? ['key' => 'deploying', 'label' => 'Deploying', 'tone' => 'accent']
                    : ApplicationPresenter::state($application),
                'href' => route('servers.applications.show', [$server, $application]),
            ])->values(),
        ]);
    }

    public function update(UpdateServerRequest $request, Server $server): RedirectResponse
    {
        $renamed = $request->has('name') && $request->string('name')->value() !== $server->name;

        $server->update($request->validated());

        if ($renamed) {
            activity()->performedOn($server)->log('server_renamed');
        }

        return back()->with('success', 'Saved.');
    }

    public function destroy(Server $server, ThisMachine $machine, CoresmithKey $keys): RedirectResponse
    {
        $this->authorize('delete', $server);

        if ($server->runs_coresmith) {
            return $this->detachThisMachine($server, $machine);
        }

        $label = $server->cloudProvider?->type->label();

        if ($label !== null && CloudTask::running($server)) {
            return back()->with('error', "Coresmith is still working on {$server->name} at {$label}. Wait for it to finish or fail, then detach it.");
        }

        $uncertain = CreateServerTask::uncertain($server);
        $exists = $label === null || (!$uncertain && $server->provider_server_id !== null);
        $key = $exists ? $keys->takeOff($server) : null;

        activity()->performedOn($server)->log('server_detached');

        $server->delete();

        $line = 'the line ending in ' . CoresmithKey::comment($server) . ' from ' . CoresmithKey::file($server) . ' there';
        $note = match ($key) {
            CoresmithKey::REMOVED => "Coresmith took its key off {$server->name}.",
            CoresmithKey::FAILED => "Coresmith couldn't take its key off {$server->name}, so remove {$line}.",
            default => "If Coresmith's key is on {$server->name}, remove {$line}.",
        };

        return to_route('dashboard')->with($key === CoresmithKey::FAILED ? 'error' : 'success', match (true) {
            $label === null => "Detached. {$note}",
            $uncertain => "Removed. Coresmith could not confirm whether {$label} created {$server->name}. Check your {$label} account and delete it there if it exists.",
            $server->provider_server_id === null => "Removed. Nothing was created at {$label}.",
            $key === CoresmithKey::REMOVED => "Detached. {$note} It keeps running at {$label}, and {$label} keeps billing for it.",
            default => "Detached. {$server->name} keeps running at {$label}, and {$label} keeps billing for it. {$note}",
        });
    }

    private function detachThisMachine(Server $server, ThisMachine $machine): RedirectResponse
    {
        try {
            $machine->remove(ThisMachine::REMOVE_SECONDS);
            $refusal = null;
        } catch (ThisMachineRefused $refused) {
            $refusal = $refused->getMessage();
        }

        activity()->performedOn($server)->log('server_detached');

        $server->delete();

        return $refusal === null
            ? to_route('dashboard')->with('success', 'Detached. Coresmith took its key off this machine and stopped managing it. Apps keep running, and so does Coresmith.')
            : to_route('dashboard')->with('error', "Detached, but Coresmith couldn't take its key off this machine: {$refusal} Remove the coresmith-this-machine lines from /root/.ssh/authorized_keys and /etc/ssh/sshd_config, and delete /etc/ssh/sshd_config.d/00-coresmith-local.conf.");
    }

    public function testConnection(Server $server, ServerConnector $connector): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_if(in_array($server->status, [ServerStatus::Creating, ServerStatus::Active, ServerStatus::Moving], true), 404);

        try {
            $connector->withKey($server);
        } catch (ServerConnectionFailed) {
            return back()->with('error', "Coresmith couldn't connect to {$server->name}.");
        }

        return back()->with('success', $server->status === ServerStatus::Pending ? "Connected. Coresmith is checking {$server->name} before it provisions." : 'Connected.');
    }

    public function connect(ConnectServerRequest $request, Server $server, ServerConnector $connector): RedirectResponse
    {
        abort_unless($server->status->isBeforeProvisioning(), 404);

        try {
            $connector->withPassword($server, $request->safe()->string('ssh_user')->value(), $request->safe()->string('password')->value());
        } catch (ServerConnectionFailed) {
            return back()->with('error', "Coresmith couldn't connect to {$server->name}.");
        }

        return back()->with('success', "Connected. Coresmith added its key and is checking {$server->name} before it provisions.");
    }

    public function audit(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_unless($server->status->isBeforeProvisioning(), 404);

        AuditServer::begin($server);

        return back()->with('success', "Checking {$server->name}. Nothing on the server changes while Coresmith looks.");
    }

    public function fixAudit(Server $server, string $fix): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_unless(
            $server->status->isBeforeProvisioning()
                && array_key_exists($fix, PreProvisioningAudit::FIXES)
                && in_array($fix, ServerAudit::fixes($server), true),
            404,
        );

        ServerAudit::start($server);
        FixAuditFinding::dispatch($server, $fix);

        return back()->with('success', 'Stopping ' . PreProvisioningAudit::FIXES[$fix] . " on {$server->name}, then checking again.");
    }

    public function provision(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        if ($server->status === ServerStatus::Failed) {
            return back()->with('error', 'Provisioning already ran on this server. Retry it from the phase that failed, or start over.');
        }

        if ($server->status->isBeforeProvisioning()) {
            if (ServerAudit::isRunning($server)) {
                return back()->with('error', 'The check is still running. Provision once it finishes.');
            }

            if (!ServerAudit::isFresh($server)) {
                AuditServer::begin($server);

                return back()->with('error', 'Coresmith checks the server before provisioning, so that check is running now. Provision once it finishes.');
            }

            if (ServerAudit::blocks($server)) {
                return back()->with('error', 'Resolve the blockers first, then check again.');
            }
        }

        abort_unless($server->status->isBeforeProvisioning(), 404);

        ProvisionServer::begin($server);

        return back()->with('success', 'Provisioning started. Every phase is logged, close this page and it keeps going.');
    }

    public function retryProvisioning(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_unless($server->status === ServerStatus::Failed, 404);

        $completed = (int) ($server->provisioning_phase ?? 0);
        $words = ['no', 'one', 'two', 'three', 'four', 'five', 'six', 'seven'];

        if (!ProvisionServer::again($server)) {
            return back()->with('success', "Provisioning is already running on {$server->name}.");
        }

        activity()->performedOn($server)->withProperties(['phase' => $completed + 1])->log('provisioning_retried');

        $kept = $completed === 0 ? 'Nothing had finished yet.' : ($completed === 1 ? 'The phase before it finished and is kept.' : "The {$words[$completed]} phases before it finished and are kept.");

        return back()->with('success', 'Retrying from phase ' . ($completed + 1) . ". {$kept}");
    }

    public function restartProvisioning(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_unless($server->status === ServerStatus::Failed, 404);

        if (!ProvisionServer::again($server, fromScratch: true)) {
            return back()->with('success', "Provisioning is already running on {$server->name}.");
        }

        activity()->performedOn($server)->log('provisioning_restarted');

        return back()->with('success', 'Starting over from phase 1. Each phase is written to be safe to run again.');
    }

    public function reboot(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $refusal = CloudTask::refusal($server, 'Reboot');

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        if ($server->unreachable_since !== null && !RebootServer::viaProvider($server)) {
            return back()->with('error', "Coresmith can't reach {$server->name} over SSH, so it can't reboot it. Restart the machine yourself.");
        }

        $busy = Deployment::query()
            ->whereIn('application_id', $server->applications()->select('id'))
            ->where('status', DeploymentStatus::Running->value)
            ->exists();

        if ($busy) {
            return back()->with('error', "A deploy is running on {$server->name}. Reboot once it finishes.");
        }

        RebootServer::dispatch($server, request()->user());

        return back()->with('success', "Reboot scheduled. {$server->name} restarts in a few seconds, and its apps start again on their own.");
    }

    public function prune(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        PruneReleases::dispatch($server);

        return back()->with('success', 'Pruning old releases. Each app keeps its last ' . ReleasePaths::KEEP_RELEASES . ' and the live one, disk readings refresh within a minute.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function added(Server $server, ServerConnector $connector, #[\SensitiveParameter] string $password): array
    {
        if ($password === '') {
            return ['success', 'Server added. Connect it with a password or one command.'];
        }

        try {
            $connector->withPassword($server, $server->ssh_user, $password);
        } catch (ServerConnectionFailed) {
            return ['error', "Server added, but Coresmith couldn't connect to it."];
        }

        return ['success', "Server added and connected. Coresmith is checking {$server->name} before it provisions."];
    }
}
