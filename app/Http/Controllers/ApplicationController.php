<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\AppSize;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Http\Requests\Application\StoreApplicationRequest;
use App\Http\Requests\Application\UpdateApplicationRequest;
use App\Jobs\DetachApplication;
use App\Jobs\ReservePortBlock;
use App\Models\Application;
use App\Models\Server;
use App\Services\Git\DeployKeyManager;
use App\Services\Git\Repository;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Panel\DeploymentPresenter;
use App\Services\Panel\Formats;
use App\Services\Panel\SmartToggles;
use App\Services\Server\PortAllocator;
use App\Services\SlugGenerator;
use App\Services\SSH\HostKeyFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

final class ApplicationController extends Controller
{
    public const SECRET_KEYS = '/(PASSWORD|SECRET|TOKEN|KEY|DSN|PRIVATE)/i';

    public function create(Server $server): Response
    {
        $this->authorize('view', $server);
        $this->authorize('create', Application::class);

        return Inertia::render('applications/Create', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => route('servers.show', $server)],
        ]);
    }

    public function store(
        StoreApplicationRequest $request,
        Server $server,
        SlugGenerator $slugs,
        PortAllocator $ports,
    ): RedirectResponse {
        $slug = $slugs->fromName($request->string('name')->value(), $server);

        $application = Application::create([
            ...$request->safe()->except([]),
            'server_id' => $server->id,
            'team_id' => $server->team_id,
            'slug' => $slug,
            'system_user' => $slugs->systemUser($slug),
            'base_port' => $ports->allocate($server),
            'status' => ApplicationStatus::Pending,
        ]);

        activity()->performedOn($application)->log('application_created');

        if ($server->status === ServerStatus::Active) {
            ReservePortBlock::dispatch($application);
        }

        return to_route('servers.applications.show', [$server, $application])
            ->with('success', 'Application created. Deploy it when you are ready.');
    }

    public function show(Request $request, Server $server, Application $application, DeployKeyManager $keys, SmartToggles $toggles): Response
    {
        $this->authorize('view', $application);
        $this->assertBelongsTo($server, $application);

        $user = $request->user();
        $canManageEnvironment = $user->can('manageEnvironment', $application);
        $latest = $application->deployments()->latest('created_at')->first();
        $running = $latest?->status === DeploymentStatus::Running ? $latest : null;
        $usesKey = $keys->usesDeployKey($application);
        $publicKey = $usesKey ? $keys->ensure($application)->deploy_key_public : null;

        return Inertia::render('applications/Show', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => route('servers.show', $server)],
            'application' => [
                'id' => $application->id,
                'name' => $application->name,
                'state' => $running === null ? ApplicationPresenter::state($application) : ['key' => 'deploying', 'label' => 'Deploying', 'tone' => 'accent'],
                'kind' => ApplicationPresenter::kind($application),
                'runtime' => ApplicationPresenter::runtime($application),
                'domain' => $application->domain,
                'additional_domains' => array_values((array) $application->additional_domains),
                'repository' => Repository::slug((string) $application->repository_url) ?? $application->repository_url,
                'branch' => $application->repository_branch,
                'current_release' => Formats::release($application->current_release),
                'power_state' => $application->power_state?->value,
                'stopped' => $application->power_state === PowerState::Stopped,
                'maintenance' => (bool) (((array) $application->toggles)['maintenance_mode'] ?? false),
                'user' => $application->system_user,
                'ports' => ApplicationPresenter::portBlock($application),
                'processes' => array_values(array_filter((array) $application->processes, 'is_array')),
                'cron_jobs' => array_values(array_filter((array) $application->cron_jobs, 'is_array')),
                'deploy_key' => $publicKey === null ? null : [
                    'fingerprint' => HostKeyFingerprint::fromPublicKey($publicKey),
                    'short' => Formats::fingerprint(HostKeyFingerprint::fromPublicKey($publicKey)),
                    'public_key' => $publicKey,
                    'repository' => Repository::slug((string) $application->repository_url),
                ],
                'environment' => $this->environmentPreview((string) $application->env_variables, $canManageEnvironment),
                'env' => $canManageEnvironment ? (string) $application->env_variables : null,
                'latest_deployment' => $latest === null ? null : [
                    ...DeploymentPresenter::row($latest->setRelation('application', $application->setRelation('server', $server))),
                    'href' => route('servers.applications.deployments.show', [$server, $application, $latest, 'from' => 'app']),
                ],
                'running_href' => $running === null ? null : route('servers.applications.deployments.show', [$server, $application, $running, 'from' => 'app']),
                'releases_href' => route('servers.applications.releases.index', [$server, $application]),
                'deploy_href' => route('servers.applications.deploy', [$server, $application]),
                'logs_href' => route('servers.applications.logs', [$server, $application]),
                'last_failed' => $latest !== null && $latest->status === DeploymentStatus::Failed,
                'last_failed_live' => $latest !== null && $latest->status === DeploymentStatus::Failed && DeploymentPresenter::reachedUsers($latest),
                'oom' => $this->recentOomKill($application),
            ],
            'toggles' => array_map(fn(array $toggle): array => [
                ...$toggle,
                'href' => route('servers.applications.toggles.update', [$server, $application, $toggle['key']]),
            ], $toggles->describe($application)),
            'can' => [
                'update' => $user->can('update', $application),
                'delete' => $user->can('delete', $application),
                'deploy' => $user->can('deploy', $application),
                'manageEnvironment' => $canManageEnvironment,
            ],
        ]);
    }

    public function update(UpdateApplicationRequest $request, Server $server, Application $application): RedirectResponse
    {
        $this->assertBelongsTo($server, $application);

        $before = $application->allDomains();

        $application->update($request->validated());

        foreach (array_diff($application->allDomains(), $before) as $domain) {
            activity()->performedOn($application)->withProperties(['domain' => $domain])->log('domain_added');
        }

        return back()->with('success', 'Saved.');
    }

    public function destroy(Server $server, Application $application): RedirectResponse
    {
        $this->authorize('delete', $application);
        $this->assertBelongsTo($server, $application);

        activity()->performedOn($application)->log('application_removed');

        $application->delete();

        DetachApplication::dispatch($application);

        return to_route('servers.show', $server)->with('success', "Removed. {$application->name} was stopped and detached.");
    }

    private function assertBelongsTo(Server $server, Application $application): void
    {
        abort_unless($application->server_id === $server->id, 404);
    }

    /**
     * @return array{process: string, when: string, limit_mb: int, size: string}|null
     */
    private function recentOomKill(Application $application): ?array
    {
        $kill = Activity::query()
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->where('description', 'app_oom_killed')
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->first();

        if ($kill === null) {
            return null;
        }

        $at = CarbonImmutable::parse((string) ($kill->properties['at'] ?? $kill->created_at));

        return [
            'process' => (string) ($kill->properties['process'] ?? 'web'),
            'when' => $at->isToday() ? 'at ' . $at->format('H:i') : 'on ' . $at->format('M j'),
            'limit_mb' => (int) ($kill->properties['limit_mb'] ?? 0),
            'size' => ucfirst((AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small)->value),
        ];
    }

    /**
     * @return list<array{key: string, value: string, secret: bool}>
     */
    private function environmentPreview(string $env, bool $reveal): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $env) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $secret = preg_match(self::SECRET_KEYS, $matches[1]) === 1;

            $lines[] = [
                'key' => $matches[1],
                'value' => $secret || !$reveal ? '••••••••' : trim($matches[2], "\"'"),
                'secret' => $secret,
            ];

            if (count($lines) === 4) {
                break;
            }
        }

        return $lines;
    }
}
