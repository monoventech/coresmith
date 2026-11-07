<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\DeploymentStatus;
use App\Exceptions\GitProviderException;
use App\Exceptions\TeamDeletionRefused;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\SourceProvider;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvoice;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Git\Contracts\GitProvider;
use App\Services\Git\GitProviderFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Throwable;

final class TeamDeletion
{
    private const SETTLED = ['canceled', 'incomplete_expired'];

    public function __construct(
        private readonly AuditLog $log,
        private readonly GitProviderFactory $git,
        private readonly CloudCatalog $catalog,
    ) {}

    public function refusal(Team $team): ?string
    {
        $subscription = $team->subscription('default');

        if ($subscription instanceof Subscription && $subscription->valid() && !$subscription->onGracePeriod()) {
            return 'End the plan from Billing first, then delete the team.';
        }

        if ($team->members()->count() > 1) {
            return 'Remove the other members first, so nobody loses access by surprise.';
        }

        return $this->providerWork($team) ?? $this->deploy($team);
    }

    public function delete(Team $team, User $by): void
    {
        $refusal = $this->refusal($team);

        if ($refusal !== null) {
            throw new TeamDeletionRefused($refusal);
        }

        $this->endSubscription($team);

        $clients = $this->gitClients($team);
        $accounts = CloudProvider::withTrashed()->where('team_id', $team->getKey())->get();
        $owner = $team->owner_id;

        DB::transaction(fn() => $this->erase($team));

        foreach ($clients as $client) {
            try {
                $client->revoke();
            } catch (Throwable) {
                report_if(app()->hasDebugModeEnabled(), new GitProviderException('Revoking a deleted team\'s Git token failed; the stored credentials were still deleted.'));
            }
        }

        $accounts->each(fn(CloudProvider $account) => $this->catalog->forget($account));

        Log::info('A team was deleted.', ['team' => $team->getKey(), 'owner' => $owner, 'by' => $by->getKey()]);
    }

    private function providerWork(Team $team): ?string
    {
        foreach ($team->servers()->whereNotNull('provider_task')->with('cloudProvider')->oldest()->orderBy('id')->get() as $server) {
            $task = CloudTask::of($server);

            if ($task === null || $task->state !== CloudTask::RUNNING) {
                continue;
            }

            $doing = match ($task->type) {
                CloudTask::CREATE => 'creating',
                CloudTask::RESIZE => 'resizing',
                CloudTask::DESTROY => 'destroying',
                default => 'working on',
            };
            $label = $server->cloudProvider?->type->label() ?? 'its provider';

            return "Coresmith is still {$doing} {$server->name} at {$label}. Delete the team once that finishes.";
        }

        return null;
    }

    private function deploy(Team $team): ?string
    {
        $deployment = Deployment::query()
            ->whereIn('application_id', $team->applications()->select('id'))
            ->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])
            ->with('application')
            ->oldest()
            ->first();

        return $deployment === null ? null : "{$deployment->application->name} is still deploying. Delete the team once the deploy finishes.";
    }

    private function endSubscription(Team $team): void
    {
        $subscription = $team->subscription('default');

        if (!$subscription instanceof Subscription || $subscription->ended() || in_array($subscription->stripe_status, self::SETTLED, true)) {
            return;
        }

        try {
            $subscription->cancelNow();
        } catch (ApiErrorException $exception) {
            Log::warning('Stripe could not cancel a plan before its team was deleted.', ['team' => $team->getKey(), 'stripe' => $exception->getMessage()]);

            throw new TeamDeletionRefused('Stripe could not cancel the plan, so nothing was deleted. Try again in a minute.');
        }
    }

    /**
     * @return list<GitProvider>
     */
    private function gitClients(Team $team): array
    {
        $clients = [];

        foreach (SourceProvider::query()->where('team_id', $team->getKey())->get() as $provider) {
            if (!$provider->type->usesTokens() || !$provider->connected()) {
                continue;
            }

            try {
                $clients[] = $this->git->for($provider);
            } catch (Throwable) {
                continue;
            }
        }

        return $clients;
    }

    private function erase(Team $team): void
    {
        $id = $team->getKey();
        $servers = Server::withTrashed()->where('team_id', $id)->select('id');
        $applications = Application::withTrashed()->where('team_id', $id)->select('id');

        $this->log->forTeam($team)->delete();

        ApiToken::query()->where('team_id', $id)->delete();
        User::query()->where('current_team_id', $id)->update(['current_team_id' => null]);
        Deployment::query()->whereIn('application_id', $applications)->delete();
        WebhookEndpoint::query()->whereIn('application_id', $applications)->delete();
        ManagedDatabase::withTrashed()->where('team_id', $id)->forceDelete();
        Application::withTrashed()->where('team_id', $id)->forceDelete();
        ServerMetric::query()->whereIn('server_id', $servers)->delete();
        Server::withTrashed()->where('team_id', $id)->forceDelete();
        SourceProvider::withTrashed()->where('team_id', $id)->forceDelete();
        CloudProvider::withTrashed()->where('team_id', $id)->forceDelete();
        Subscription::query()->where('team_id', $id)->delete();
        TeamInvoice::query()->where('team_id', $id)->delete();

        $team->forceDelete();
    }
}
