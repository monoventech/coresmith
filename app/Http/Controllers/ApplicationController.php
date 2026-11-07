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
use App\Enums\SourceProviderType;
use App\Http\Requests\Application\StoreApplicationRequest;
use App\Http\Requests\Application\UpdateApplicationRequest;
use App\Jobs\CheckCertificates;
use App\Jobs\DetachApplication;
use App\Jobs\ReservePortBlock;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Services\Billing\SubscriptionCheck;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Git\DeployKeyManager;
use App\Services\Git\Repository;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Panel\CronJobs;
use App\Services\Panel\DeploymentPresenter;
use App\Services\Panel\Formats;
use App\Services\Panel\RollbackHistory;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeDetector;
use App\Services\Runtime\RuntimeLifecycle;
use App\Services\Server\CertificateCheck;
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

    public function create(Server $server, Catalog $catalog, RuntimeLifecycle $lifecycle): Response
    {
        $this->authorize('view', $server);
        $this->authorize('create', Application::class);

        return Inertia::render('applications/Create', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => route('servers.show', $server)],
            'runtimes' => collect(['php', 'ruby', 'bun', 'nodejs', 'deno'])->mapWithKeys(fn(string $runtime): array => [$runtime => [
                'label' => $catalog->label($runtime),
                'recommended' => $catalog->recommendedLine($runtime),
                'sources' => RuntimeDetector::SOURCES[$runtime],
                'options' => $lifecycle->options($runtime),
            ]])->all(),
            'github' => [
                'connections' => SourceProvider::query()
                    ->where('team_id', $server->team_id)
                    ->where('type', SourceProviderType::GitHubApp->value)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get()
                    ->filter(fn(SourceProvider $provider): bool => $provider->connected())
                    ->map(fn(SourceProvider $provider): array => [
                        'id' => $provider->id,
                        'account' => $provider->name,
                        'repositories_href' => route('source-providers.repositories', $provider),
                        'manage_url' => $provider->manageUrl(),
                    ])
                    ->values()
                    ->all(),
                'settings_href' => route('source-providers.index'),
            ],
        ]);
    }

    public function store(
        StoreApplicationRequest $request,
        Server $server,
        SlugGenerator $slugs,
        PortAllocator $ports,
        SubscriptionCheck $subscriptions,
    ): RedirectResponse {
        $subscriptions->confirm($server->team);

        $slug = $slugs->fromName($request->string('name')->value(), $server);

        $runtime = $request->runtime();
        $line = $request->runtimeLine();

        $connection = $request->githubConnection();
        $repository = $request->githubRepository();

        $application = Application::create([
            ...$request->safe()->except(['runtime_version', 'acknowledge_eol', 'source_provider_id', 'repository_id']),
            ...($connection === null || $repository === null ? [] : [
                'repository_url' => 'https://github.com/' . $repository['full_name'] . '.git',
                'repository_provider' => 'github',
                'source_provider_id' => $connection->getKey(),
                'repository_id' => $repository['id'],
            ]),
            'runtime_pins' => $line === null ? null : [$runtime => $line],
            'server_id' => $server->id,
            'team_id' => $server->team_id,
            'slug' => $slug,
            'system_user' => $slugs->systemUser($slug),
            'base_port' => $ports->allocate($server),
            'status' => ApplicationStatus::Pending,
        ]);

        activity()->performedOn($application)->log('application_created');

        if ($line !== null && $request->isEndOfLife((string) $runtime, $line)) {
            activity()->performedOn($application)->withProperties(['runtime' => $runtime, 'line' => $line])->log('runtime_eol_chosen');
        }

        if ($server->status === ServerStatus::Active) {
            ReservePortBlock::dispatch($application);
        }

        return to_route('servers.applications.show', [$server, $application])
            ->with('success', 'Application created. Deploy it when you are ready.');
    }

    public function show(Request $request, Server $server, Application $application, DeployKeyManager $keys, SmartToggles $toggles, RollbackHistory $rollbacks, CertificateCheck $certificates, CronJobs $cron): Response
    {
        $this->authorize('view', $application);
        $this->assertBelongsTo($server, $application);

        if ($server->unreachable_since === null && CertificateCheck::domains($application) !== [] && $certificates->stale($application)) {
            rescue(function () use ($server): void {
                CheckCertificates::dispatch($server);
            }, report: true);
        }

        $user = $request->user();
        $canManageEnvironment = $user->can('manageEnvironment', $application);
        $latest = $application->deployments()->latest('created_at')->first();
        $running = $latest?->status === DeploymentStatus::Running ? $latest : null;
        $failedLive = $latest !== null && $latest->status === DeploymentStatus::Failed && DeploymentPresenter::reachedUsers($latest);
        $rollback = $rollbacks->sinceLastLive($application);
        $github = $this->github($application);
        $usesKey = !($github['usable'] ?? false) && $keys->usesDeployKey($application);
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
                'certificates' => $certificates->forApplication($application),
                'repository' => Repository::slug((string) $application->repository_url) ?? $application->repository_url,
                'branch' => $application->repository_branch,
                'current_release' => Formats::release($application->current_release),
                'power_state' => $application->power_state?->value,
                'stopped' => $application->power_state === PowerState::Stopped,
                'maintenance' => (bool) (((array) $application->toggles)['maintenance_mode'] ?? false),
                'user' => $application->system_user,
                'ports' => ApplicationPresenter::portBlock($application),
                'processes' => array_values(array_filter((array) $application->processes, 'is_array')),
                'deploy_key' => $publicKey === null ? null : [
                    'fingerprint' => HostKeyFingerprint::fromPublicKey($publicKey),
                    'short' => Formats::fingerprint(HostKeyFingerprint::fromPublicKey($publicKey)),
                    'public_key' => $publicKey,
                    'repository' => Repository::slug((string) $application->repository_url),
                ],
                'github' => $github,
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
                'last_failed_live' => $failedLive,
                'last_failed_reason' => $failedLive && $latest->release === $application->current_release ? $latest->failure_reason : null,
                'rollback_failed' => in_array($rollback?->description, [RollbackHistory::DID_NOT_START, RollbackHistory::WORKER_DID_NOT_START], true) ? [
                    'release' => Formats::release((string) ($rollback->properties['to'] ?? $application->current_release)),
                    'error' => (string) ($rollback->properties['error'] ?? ''),
                    'worker' => $rollback->description === RollbackHistory::WORKER_DID_NOT_START ? (string) ($rollback->properties['worker'] ?? '') : null,
                ] : null,
                'oom' => $this->recentOomKill($application),
                'runtime_eol' => ApplicationPresenter::runtimeEol($application),
            ],
            'toggles' => array_map(fn(array $toggle): array => [
                ...$toggle,
                'href' => route('servers.applications.toggles.update', [$server, $application, $toggle['key']]),
            ], $toggles->describe($application)),
            'cron' => $this->cron($server, $application, $cron),
            'can' => [
                'update' => $user->can('update', $application),
                'delete' => $user->can('delete', $application),
                'deploy' => $user->can('deploy', $application),
                'manageEnvironment' => $canManageEnvironment,
                'viewLogs' => $user->can('viewLogs', $application),
            ],
        ]);
    }

    public function update(UpdateApplicationRequest $request, Server $server, Application $application): RedirectResponse
    {
        $this->assertBelongsTo($server, $application);

        $before = $application->allDomains();

        $application->update($request->validated());

        $domains = $application->allDomains();

        foreach (array_diff($domains, $before) as $domain) {
            activity()->performedOn($application)->withProperties(['domain' => $domain])->log('domain_added');
        }

        if ($domains !== $before && $application->current_release !== null) {
            SyncApplicationRoute::dispatch($application, unroute: $domains === []);
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
     * @return array<string, mixed>
     */
    private function cron(Server $server, Application $application, CronJobs $cron): array
    {
        $card = $cron->describe($application);

        return [
            ...$card,
            'jobs' => array_map(fn(array $job): array => [
                ...$job,
                'href' => route('servers.applications.cron-jobs.update', [$server, $application, $job['id']]),
            ], $card['jobs']),
            'store_href' => route('servers.applications.cron-jobs.store', [$server, $application]),
            'sync_href' => route('servers.applications.cron-jobs.sync', [$server, $application]),
            'log_href' => route('servers.applications.cron-jobs.log', [$server, $application]),
        ];
    }

    /**
     * @return array{repository: string, branch: string, usable: bool, problem: 'removed'|'suspended'|null, settings_href: string}|null
     */
    private function github(Application $application): ?array
    {
        $connection = $application->githubConnection();

        if ($connection === null) {
            return null;
        }

        return [
            'repository' => $application->repositoryFullName() ?? (string) $application->repository_url,
            'branch' => (string) $application->repository_branch,
            'usable' => $application->usesGitHubToken(),
            'problem' => $application->githubProblem(),
            'settings_href' => route('source-providers.index'),
        ];
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

        foreach (array_slice(app(EnvironmentFile::class)->parse($env), 0, 4, true) as $key => $value) {
            $secret = preg_match(self::SECRET_KEYS, $key) === 1;

            $lines[] = [
                'key' => $key,
                'value' => $secret || !$reveal ? '••••••••' : $value,
                'secret' => $secret,
            ];
        }

        return $lines;
    }
}
