<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CancelOutcome;
use App\Enums\DeploymentStatus;
use App\Jobs\DeployApplication;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Deployment\DeployGuard;
use App\Services\Deployment\DeploymentCancellation;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Panel\DeploymentPresenter;
use App\Services\Panel\Formats;
use App\Services\Panel\Realtime;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class DeploymentController extends Controller
{
    public const ORIGINS = ['fleet', 'deployments', 'app', 'releases', 'setup'];

    public function deploy(Request $request, Server $server, Application $application, DeployGuard $guard): RedirectResponse
    {
        $this->authorize('deploy', $application);

        abort_unless($application->server_id === $server->id, 404);

        $from = in_array($request->input('from'), self::ORIGINS, true) ? $request->input('from') : 'fleet';
        $refusal = $guard->refusal($application);

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        $deployment = Deployment::create([
            'application_id' => $application->id,
            'triggered_by' => $request->user()?->id,
            'branch' => $application->repository_branch,
        ]);

        DeployApplication::dispatch($deployment, now()->format('Ymd-His'));

        return to_route('servers.applications.deployments.show', [$server, $application, $deployment, 'from' => $from])
            ->with('success', "Deploying {$application->name}. The current release keeps serving until the symlink swap.");
    }

    public function show(Request $request, Server $server, Application $application, Deployment $deployment, DeploymentPhases $phases): Response
    {
        $this->authorize('view', $application);

        abort_unless($application->server_id === $server->id, 404);
        abort_unless($deployment->application_id === $application->id, 404);

        $deployment->setRelation('application', $application->setRelation('server', $server));

        return Inertia::render('deployments/Show', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => app(TeamAccess::class)->canSeeServer($request->user(), $server) ? route('servers.show', $server) : null],
            'application' => [
                'id' => $application->id,
                'name' => $application->name,
                'href' => route('servers.applications.show', [$server, $application]),
                'releases_href' => route('servers.applications.releases.index', [$server, $application]),
                'deploy_href' => route('servers.applications.deploy', [$server, $application]),
                'current_release' => Formats::release($application->current_release),
            ],
            'deployment_links' => [
                'cancel' => route('servers.applications.deployments.cancel', [$server, $application, $deployment]),
            ],
            'deployment' => self::present($deployment, $phases),
            'back' => $this->back((string) $request->query('from'), $server, $application),
            'streaming' => Realtime::available(),
            'can' => ['deploy' => $request->user()->can('deploy', $application)],
        ]);
    }

    public function rollback(Request $request, Server $server, Application $application): RedirectResponse
    {
        $this->authorize('deploy', $application);

        abort_unless($application->server_id === $server->id, 404);

        $validated = $request->validate([
            'release' => ['nullable', 'string', 'regex:' . RollbackApplication::RELEASE_PATTERN, Rule::exists('deployments', 'release')->where('application_id', $application->id)],
        ]);

        if ($application->deployments()->whereIn('status', [DeploymentStatus::Pending->value, DeploymentStatus::Running->value])->exists()) {
            return back()->with('error', "A deploy is running for {$application->name}. Cancel it or let it finish, then roll back.");
        }

        $refusal = CloudTask::refusal($server, 'Roll back');

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        RollbackApplication::dispatch($application, $validated['release'] ?? null);

        $target = isset($validated['release']) ? ' to releases/' . Formats::release($validated['release']) : ' to the previous release';

        return back()->with('success', "Rolling back. The symlink re-points{$target}, nothing is rebuilt.");
    }

    public function cancel(Request $request, Server $server, Application $application, Deployment $deployment, DeploymentCancellation $cancellation): RedirectResponse
    {
        $this->authorize('deploy', $application);

        abort_unless($application->server_id === $server->id, 404);
        abort_unless($deployment->application_id === $application->id, 404);

        return match ($cancellation->request($deployment, $request->user())) {
            CancelOutcome::Cancelled => back()->with('success', 'Cancelled before it started. Nothing changed.'),
            CancelOutcome::Requested, CancelOutcome::AlreadyRequested => back()->with('success', 'Cancelling. The current release keeps serving.'),
            CancelOutcome::TooLate => back()->with('error', 'Migrations have started, so this deploy finishes on its own. Roll back afterwards if you need to.'),
            CancelOutcome::Blueprint => back()->with('error', "This app deploys with a custom blueprint, which can run migrations, so it can't be stopped partway."),
            CancelOutcome::Finished => back()->with('error', 'This deploy has already finished.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Deployment $deployment, DeploymentPhases $phases): array
    {
        $states = $phases->states($deployment);
        $failed = collect($states)->firstWhere('state', 'failed');

        return [
            'id' => $deployment->id,
            'status' => $deployment->status->value,
            'state' => DeploymentPresenter::state($deployment),
            'log' => (string) $deployment->log,
            'phases' => $states,
            'failed_phase' => $failed['key'] ?? null,
            'failure_reason' => $deployment->failure_reason,
            'live_answer' => $deployment->live_status === null ? null : ['status' => $deployment->live_status, 'detail' => $deployment->live_detail],
            'start_failed' => (bool) $deployment->start_failed,
            'failed_worker' => $deployment->failed_worker,
            'failed_step' => $deployment->failed_step,
            'reached_users' => DeploymentPresenter::reachedUsers($deployment),
            'cancel_requested' => $deployment->cancel_requested_at !== null,
            'cancelled_by' => $deployment->cancelledBy?->name,
            'cancellable' => DeploymentCancellation::cancellable($deployment),
            'release' => Formats::release($deployment->release),
            'branch' => $deployment->branch,
            'commit' => $deployment->commit_hash === null ? null : substr($deployment->commit_hash, 0, 7),
            'commit_message' => $deployment->commit_message,
            'tag' => $deployment->tag,
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            'created_at' => $deployment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{label: string, href: string}
     */
    private function back(string $from, Server $server, Application $application): array
    {
        return match ($from) {
            'app' => ['label' => $application->name, 'href' => route('servers.applications.show', [$server, $application])],
            'deployments' => ['label' => 'Deployments', 'href' => route('deployments.index')],
            'releases' => ['label' => 'Releases', 'href' => route('servers.applications.releases.index', [$server, $application])],
            default => ['label' => 'Fleet', 'href' => route('dashboard')],
        };
    }
}
