<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\DeploymentStatus;
use App\Jobs\SyncApplicationCron;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Runtime\Catalog;
use App\Services\Server\CertificateCheck;
use App\Services\Updates\UpdateStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

final class ActivityFeed
{
    private const QUIET = ['ssh_command_executed', 'authorized_keys_synced', SmartToggles::STARTED];

    private const PANEL_UPDATES = ['panel_updated', 'panel_update_rolled_back', 'panel_update_failed'];

    /**
     * @return list<array{time: string, at: string, parts: list<array{text: string, mono: bool}>, href: string|null}>
     */
    public function forTeam(Team $team, int $limit = 5): array
    {
        $servers = Server::withTrashed()->where('team_id', $team->getKey())->get()->keyBy('id');
        $applications = Application::withTrashed()->where('team_id', $team->getKey())->with(['server' => fn($query) => $query->withTrashed()])->get()->keyBy('id');
        $databases = ManagedDatabase::withTrashed()->where('team_id', $team->getKey())->get()->keyBy('id');
        $accounts = CloudProvider::withTrashed()->where('team_id', $team->getKey())->get()->keyBy('id');

        return $this->merge($servers, $applications, $databases, $accounts, $limit, panel: true);
    }

    /**
     * @return list<array{time: string, at: string, parts: list<array{text: string, mono: bool}>, href: string|null}>
     */
    public function forServer(Server $server, int $limit = 25): array
    {
        $applications = Application::withTrashed()->where('server_id', $server->getKey())->with('server')->get()->keyBy('id');
        $databases = ManagedDatabase::withTrashed()->where('server_id', $server->getKey())->get()->keyBy('id');

        return $this->merge(collect([$server->getKey() => $server]), $applications, $databases, collect(), $limit);
    }

    /**
     * @param  Collection<string, Server>  $servers
     * @param  Collection<string, Application>  $applications
     * @param  Collection<string, ManagedDatabase>  $databases
     * @param  Collection<string, CloudProvider>  $accounts
     * @return list<array{time: string, at: string, parts: list<array{text: string, mono: bool}>, href: string|null}>
     */
    private function merge(Collection $servers, Collection $applications, Collection $databases, Collection $accounts, int $limit, bool $panel = false): array
    {
        $rows = [];

        $activities = Activity::query()
            ->whereNotIn('description', self::QUIET)
            ->where(function ($query) use ($servers, $applications, $databases, $accounts, $panel): void {
                $query->where(fn($inner) => $inner->where('subject_type', (new Server)->getMorphClass())->whereIn('subject_id', $servers->keys()))
                    ->orWhere(fn($inner) => $inner->where('subject_type', (new Application)->getMorphClass())->whereIn('subject_id', $applications->keys()))
                    ->orWhere(fn($inner) => $inner->where('subject_type', (new ManagedDatabase)->getMorphClass())->whereIn('subject_id', $databases->keys()))
                    ->orWhere(fn($inner) => $inner->where('subject_type', (new CloudProvider)->getMorphClass())->whereIn('subject_id', $accounts->keys()));

                if ($panel) {
                    $query->orWhere(fn($inner) => $inner->whereNull('subject_type')->whereIn('description', self::PANEL_UPDATES));
                }
            })
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        $connections = $this->relinkedTo($activities);

        foreach ($activities as $activity) {
            $row = match (true) {
                $activity->subject_type === null => $this->describePanel($activity),
                $activity->subject_type === (new CloudProvider)->getMorphClass() => $this->describeAccount($activity, $accounts->get($activity->subject_id)),
                default => $this->describe($activity, $servers, $applications, $databases, $connections),
            };

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $deployments = Deployment::query()
            ->whereIn('application_id', $applications->keys())
            ->whereNot('status', DeploymentStatus::Cancelled->value)
            ->whereNotNull('started_at')
            ->latest('started_at')
            ->limit($limit)
            ->get();

        foreach ($deployments as $deployment) {
            $application = $applications->get($deployment->application_id);

            if ($application !== null) {
                $rows[] = $this->deployment($deployment, $application);
            }
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return array{time: string, at: string, parts: list<array{text: string, mono: bool}>, href: string|null}|null
     */
    private function describePanel(Activity $activity): ?array
    {
        $properties = $activity->properties?->toArray() ?? [];
        $to = (string) ($properties['to'] ?? '');
        $from = (string) ($properties['from'] ?? '');
        $step = lcfirst(UpdateStatus::STEPS[(string) ($properties['step'] ?? '')] ?? 'updating');

        $text = match ($activity->description) {
            'panel_updated' => ($properties['trigger'] ?? null) === 'auto'
                ? "Auto-update applied, panel v{$to}, backup taken, health check passed"
                : "Panel updated to v{$to}, backup taken, health check passed",
            'panel_update_rolled_back' => "Panel update to v{$to} failed while {$step}, rolled back to v{$from}",
            'panel_update_failed' => "Panel update to v{$to} failed and needs attention",
            default => null,
        };

        return $text === null ? null : $this->row($activity->created_at, [$this->text($text)], route('settings.general'));
    }

    /**
     * @return array{time: string, at: string, parts: list<array{text: string, mono: bool}>, href: string|null}|null
     */
    private function describeAccount(Activity $activity, ?CloudProvider $account): ?array
    {
        $properties = $activity->properties?->toArray() ?? [];
        $provider = (string) ($properties['provider'] ?? $account?->type->label());
        $name = (string) ($properties['name'] ?? $account?->name);

        $parts = match ($activity->description) {
            'cloud_provider_connected' => [$this->text($provider . ' connected as '), $this->mono($name)],
            'cloud_provider_token_replaced' => [$this->text('Token replaced for '), $this->mono($name)],
            'cloud_provider_removed' => [$this->text($provider . ' account '), $this->mono($name), $this->text(' removed')],
            default => null,
        };

        return $parts === null ? null : $this->row($activity->created_at, $parts, route('teams.index'));
    }

    /**
     * @return array{time: string, at: string, parts: list<array{text: string, mono: bool}>, href: string|null}
     */
    private function deployment(Deployment $deployment, Application $application): array
    {
        $release = Formats::release($deployment->release);
        $href = route('servers.applications.deployments.show', [$application->server_id, $application->getKey(), $deployment]);

        return match ($deployment->status) {
            DeploymentStatus::Succeeded => $this->row($deployment->finished_at ?? $deployment->started_at, [
                $this->mono($application->name),
                $this->text(' deployed to ' . $application->server?->name . ($deployment->tag ? ', ' . $deployment->tag : ($release ? ', ' . $release : '')) . ', zero downtime'),
            ], $href),
            DeploymentStatus::Failed => $this->row($deployment->finished_at ?? $deployment->started_at, [
                $this->text('Deploy failed, '),
                $this->mono($application->name),
                $this->text(', the current release kept serving'),
            ], $href),
            default => $this->row($deployment->started_at, [
                $this->text('Deploy started, '),
                $this->mono($application->name),
                $this->text(implode(', ', array_filter([$release ? ' release ' . $release : null, $deployment->branch ? 'branch ' . $deployment->branch : null]))),
            ], $href),
        };
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return Collection<string, string>
     */
    private function relinkedTo(Collection $activities): Collection
    {
        $ids = $activities->where('description', 'application_relinked')
            ->map(fn(Activity $activity): mixed => $activity->properties?->get('to'))
            ->filter(fn(mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        return $ids->isEmpty() ? collect() : SourceProvider::withTrashed()->whereIn('id', $ids)->pluck('name', 'id');
    }

    /**
     * @param  Collection<string, Server>  $servers
     * @param  Collection<string, Application>  $applications
     * @param  Collection<string, ManagedDatabase>  $databases
     * @param  Collection<string, string>  $connections
     * @return array{time: string, at: string, parts: list<array{text: string, mono: bool}>, href: string|null}|null
     */
    private function describe(Activity $activity, Collection $servers, Collection $applications, Collection $databases, Collection $connections): ?array
    {
        $properties = $activity->properties?->toArray() ?? [];
        $server = $servers->get($activity->subject_id);
        $application = $applications->get($activity->subject_id);
        $database = $databases->get($activity->subject_id);

        $serverHref = $server !== null && !$server->trashed() ? route('servers.show', $server) : null;
        $applicationHref = $application !== null && !$application->trashed() ? route('servers.applications.show', [$application->server_id, $application]) : null;

        $parts = match ($activity->description) {
            'server_added' => [$this->mono($server?->name), $this->text(' added to the panel, waiting for its key')],
            'server_creation_started' => [$this->mono($server?->name), $this->text(' is being created at ' . ($properties['provider'] ?? 'its provider') . ', ' . ($properties['size'] ?? '') . ' in ' . ($properties['region'] ?? ''))],
            'server_created_at_provider' => [$this->mono($server?->name), $this->text(' created at ' . ($properties['provider'] ?? 'its provider') . $this->took($properties['seconds'] ?? null) . ', checking it before provisioning')],
            'server_creation_failed' => [$this->text('Creating '), $this->mono($server?->name), $this->text(' at ' . ($properties['provider'] ?? 'its provider') . ' failed')],
            'server_destroyed' => [$this->mono($server?->name), $this->text(' destroyed at ' . ($properties['provider'] ?? 'its provider') . ', billing for it stopped')],
            'server_destroy_failed' => [$this->text('Destroying '), $this->mono($server?->name), $this->text(' at ' . ($properties['provider'] ?? 'its provider') . ' failed')],
            'server_provisioned' => [$this->mono($server?->name), $this->text(' provisioned and hardened' . $this->took($properties['duration_seconds'] ?? null))],
            'server_renamed' => [$this->text('Server renamed to '), $this->mono($server?->name)],
            'server_rebooted' => [$this->mono($server?->name), $this->text(' rebooted, apps came back via systemd')],
            'server_reboot_failed' => [$this->mono($server?->name), $this->text(' did not reboot. ' . ($properties['reason'] ?? 'The provider did not answer.'))],
            'server_resized' => [$this->mono($server?->name), $this->text(' resized from ' . ($properties['from'] ?? 'its old size') . ' to ' . ($properties['to'] ?? 'a new size'))],
            'server_resize_failed' => [$this->text('Resizing '), $this->mono($server?->name), $this->text(' failed')],
            'server_audited' => [$this->text('Pre-provisioning check on '), $this->mono($server?->name), $this->text(', ' . (int) ($properties['blockers'] ?? 0) . ' blocking, ' . (int) ($properties['warnings'] ?? 0) . ' to review')],
            'provisioning_retried' => [$this->text('Provisioning resumed on '), $this->mono($server?->name), $this->text(' from phase ' . (int) ($properties['phase'] ?? 1))],
            'provisioning_restarted' => [$this->text('Provisioning started over on '), $this->mono($server?->name)],
            'audit_fix_applied' => [$this->mono((string) ($properties['service'] ?? '')), $this->text(' stopped and disabled on '), $this->mono($server?->name), $this->text(' so Caddy can take ports 80 and 443')],
            'server_detached' => [$this->mono($server?->name), $this->text(' detached, the VM itself is untouched')],
            'removed_apps_cleared' => [$this->text('Folders cleared on '), $this->mono($server?->name), $this->text(' for ' . (int) ($properties['apps'] ?? 0) . ' apps removed over 7 days ago')],
            'releases_pruned' => [$this->text('Old releases pruned on '), $this->mono($server?->name), $this->text(', ' . (int) ($properties['removed'] ?? 0) . ' removed')],
            'disk_pressure' => ($properties['level'] ?? 'warning') === 'critical'
                ? [$this->text('Disk at ' . (int) ($properties['percent'] ?? 0) . '% on '), $this->mono($server?->name), $this->text(', deploys are paused until space is freed')]
                : [$this->text('Disk warning, '), $this->mono($server?->name), $this->text(' at ' . (int) ($properties['percent'] ?? 0) . '%, consider pruning old releases')],
            'memory_pressure' => [$this->text('Memory warning, '), $this->mono($server?->name), $this->text(' at ' . (int) ($properties['percent'] ?? 0) . '% for three minutes')],
            'provisioning_failed' => [$this->text('Provisioning stopped on '), $this->mono($server?->name), $this->text(' at phase ' . (int) ($properties['phase'] ?? 1) . ', ' . ($properties['name'] ?? 'Provisioning'))],
            'firewall_rule_changed' => [$this->text('Firewall ' . ($properties['action'] ?? 'changed') . ' port ' . ($properties['port'] ?? '') . ' on '), $this->mono($server?->name)],
            'server_unreachable' => [$this->mono($server?->name), $this->text(' stopped answering over SSH')],
            'server_reachable' => [$this->mono($server?->name), $this->text(' answering again' . $this->after($properties['seconds'] ?? null))],
            'ssh_host_key_changed' => [$this->mono($server?->name), $this->text(' presented a different host key, operations paused')],
            'ssh_host_key_accepted' => [$this->text('New host key trusted on '), $this->mono($server?->name)],
            'ssh_host_key_rejected' => [$this->text('Host key change dismissed on '), $this->mono($server?->name)],
            'apparmor_profiles_in_complain_mode' => [$this->text('AppArmor has ' . (int) ($properties['profiles_in_complain_mode'] ?? 0) . ' profiles in complain mode on '), $this->mono($server?->name)],
            'application_created' => [$this->mono($application?->name), $this->text(' created on ' . $application?->server?->name)],
            'application_renamed' => [$this->text('Application renamed to '), $this->mono($application?->name)],
            'application_removed' => [$this->mono($application?->name), $this->text(' stopped and detached from ' . $application?->server?->name)],
            'deployment_cancelled' => [$this->text('Deploy cancelled, '), $this->mono($application?->name), $this->text($this->cancelledBy($properties['by'] ?? null) . ', nothing changed')],
            'application_rolled_back' => [$this->mono($application?->name), $this->text(' rolled back to ' . Formats::release($properties['to'] ?? null) . ', no rebuild')],
            'application_relinked' => [
                $this->mono($application?->name),
                $this->text(' now clones '),
                $this->mono((string) ($properties['repository'] ?? '')),
                ...($connections->has($properties['to'] ?? '') ? [$this->text(' through GitHub on '), $this->mono($connections->get($properties['to']))] : [$this->text(' through another GitHub connection')]),
            ],
            RollbackHistory::WORKER_DID_NOT_START => [$this->mono($application?->name), $this->text(' rolled back to ' . Formats::release($properties['to'] ?? null) . ', but ' . ($properties['worker'] ?? 'a worker') . ' did not start. Open the app to see why.')],
            'rollback_blocked' => [$this->mono($application?->name), $this->text(' was not rolled back, a deploy was running. Roll back again once it finishes.')],
            'rollback_processes_failed' => [$this->mono($application?->name), $this->text(' rolled back to ' . Formats::release($properties['to'] ?? null) . ', but its processes did not start. Open the app to see why, then deploy a release that starts.')],
            'application_stopped' => [$this->mono($application?->name), $this->text(' stopped, visitors see the maintenance page')],
            'application_started' => [$this->mono($application?->name), $this->text(' started again')],
            'certificate_expiring' => [$this->text('Certificate for '), $this->mono((string) ($properties['domain'] ?? '')), $this->text(' on '), $this->mono($application?->name), $this->text(' ' . CertificateCheck::expiry((int) ($properties['days'] ?? 0)))],
            'app_oom_killed' => [$this->mono($application?->name), $this->text(' ' . ($properties['process'] ?? 'a') . ' process killed for going over its ' . (int) ($properties['limit_mb'] ?? 0) . ' MB memory limit, systemd started it again')],
            'runtime_eol_chosen' => [$this->mono($application?->name), $this->text(' was added on ' . app(Catalog::class)->label((string) ($properties['runtime'] ?? '')) . ' ' . ($properties['line'] ?? '') . ', which no longer gets security fixes')],
            CronJobs::ADDED => [$this->text('Cron job added to '), $this->mono($application?->name), $this->text(', ' . CronSchedule::phrase((string) ($properties['schedule'] ?? '')))],
            CronJobs::UPDATED => [$this->text('Cron job changed on '), $this->mono($application?->name), $this->text(', runs ' . CronSchedule::phrase((string) ($properties['schedule'] ?? '')))],
            CronJobs::REMOVED => [$this->text('Cron job removed from '), $this->mono($application?->name)],
            SyncApplicationCron::FAILED => [$this->text("Couldn't write the cron jobs for "), $this->mono($application?->name), $this->text('. ' . ($properties['error'] ?? 'The server did not respond.'))],
            'maintenance_enabled' => [$this->text('Maintenance page turned on for '), $this->mono($application?->name)],
            'maintenance_disabled' => [$this->text('Maintenance page turned off for '), $this->mono($application?->name)],
            'environment_updated' => [$this->text('Environment updated on '), $this->mono($application?->name)],
            'toggle_changed' => match (true) {
                ($properties['toggle'] ?? null) !== SmartToggles::AUTO_DEPLOY => [$this->text('Smart toggle ' . (($properties['on'] ?? false) ? 'enabled' : 'disabled') . ', ' . ($properties['label'] ?? 'a toggle') . ' on '), $this->mono($application?->name), $this->text(', no deployment needed')],
                (bool) ($properties['on'] ?? false) => [$this->text('Auto deploy turned on for '), $this->mono($application?->name), $this->text(', it deploys the branch if the live release is behind')],
                default => [$this->text('Auto deploy turned off for '), $this->mono($application?->name)],
            },
            'processes_sync_failed' => [$this->text('Couldn\'t apply the process change on '), $this->mono($application?->name), $this->text(', ' . ($properties['error'] ?? 'the server did not respond'))],
            SmartToggles::START_FAILED => [$this->text(($properties['label'] ?? 'A worker') . ' did not start on '), $this->mono($application?->name), $this->text(', so its toggle is off again')],
            'domain_added' => [$this->mono((string) ($properties['domain'] ?? '')), $this->text(' added to '), $this->mono($application?->name)],
            'port_block_moved' => [$this->mono($application?->name), $this->text(' moved to ports ' . (int) ($properties['to'] ?? 0) . '–' . ((int) ($properties['to'] ?? 0) + 9) . ', ' . ($properties['process'] ?? 'another process') . ' already listens on ' . (int) ($properties['port'] ?? 0))],
            'webhook_signature_rejected' => [$this->text((int) ($properties['count'] ?? 1) > 1 ? ((int) $properties['count']) . ' webhooks with a bad signature were rejected' : 'A webhook with a bad signature was rejected')],
            'database_created' => [$this->text('Database '), $this->mono($database?->name), $this->text(' created')],
            'database_backed_up' => [$this->text('Backup taken of '), $this->mono($database?->name)],
            default => null,
        };

        if ($parts === null) {
            return null;
        }

        $href = $application !== null ? $applicationHref : $serverHref;

        return $this->row($activity->created_at, $parts, $href);
    }

    private function took(mixed $seconds): string
    {
        if (!is_numeric($seconds)) {
            return '';
        }

        $seconds = (int) $seconds;

        return ' in ' . intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
    }

    private function after(mixed $seconds): string
    {
        return is_numeric($seconds) ? ' after ' . Formats::downFor((int) $seconds) : '';
    }

    private function cancelledBy(mixed $name): string
    {
        return is_string($name) && $name !== '' ? ', by ' . $name : '';
    }

    /**
     * @param  list<array{text: string, mono: bool}>  $parts
     * @return array{time: string, at: string, parts: list<array{text: string, mono: bool}>, href: string|null}
     */
    private function row(?CarbonInterface $moment, array $parts, ?string $href): array
    {
        return [
            'time' => (string) Formats::clock($moment),
            'at' => (string) $moment?->toIso8601String(),
            'parts' => array_values(array_filter($parts, static fn(array $part): bool => $part['text'] !== '')),
            'href' => $href,
        ];
    }

    /**
     * @return array{text: string, mono: bool}
     */
    private function text(string $text): array
    {
        return ['text' => $text, 'mono' => false];
    }

    /**
     * @return array{text: string, mono: bool}
     */
    private function mono(?string $text): array
    {
        return ['text' => (string) $text, 'mono' => true];
    }
}
