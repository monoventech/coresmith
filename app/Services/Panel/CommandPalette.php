<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Ability;
use App\Enums\DeploymentStatus;
use App\Enums\Edition;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\BillingPresenter;
use App\Services\Teams\RouteKeys;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\Eloquent\Collection;

final class CommandPalette
{
    private const ENGINES = ['mysql' => 'MySQL', 'postgresql' => 'PostgreSQL', 'redis' => 'Redis'];

    /**
     * @var array<string, bool>
     */
    private array $terminals = [];

    public function __construct(private readonly User $user, private readonly ?Team $team) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        if ($this->team === null) {
            return [...$this->actions(null), ...$this->pages()];
        }

        $fleet = new FleetSnapshot($this->team, $this->user);
        $applications = app(TeamAccess::class)->applications($this->user, $this->team)->with(['server', 'project'])->orderBy('name')->get();

        return [
            ...$this->actions($fleet),
            ...$this->pages(),
            ...$this->projects($applications),
            ...$this->applications($applications),
            ...$this->servers($fleet),
            ...$this->databases(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actions(?FleetSnapshot $fleet): array
    {
        $running = $fleet?->running()->first();
        $application = $running?->application;

        return array_values(array_filter([
            $running === null || $application === null ? null : self::action('watch', 'Watch the running deploy', 'rocket', implode(' · ', array_filter([$application->name, $running->branch, $running->commit_hash === null ? null : substr($running->commit_hash, 0, 7)])), route('servers.applications.deployments.show', [app(RouteKeys::class)->server($application->server_id), $application, $running])),
            $this->rollback(),
            $this->can(Ability::CreateApplication) ? self::action('add-app', 'Add an app', 'plus', 'from a repository', route('applications.new')) : null,
            $this->can(Ability::CreateServer) ? self::action('provision', 'Provision a server', 'plus', 'about six minutes', route('servers.create')) : null,
            $this->can(Ability::ManageProjects) ? self::action('create-project', 'Create a project', 'folder', 'group apps and databases', route('projects.index', ['create' => 1])) : null,
            ['id' => 'action:theme', 'kind' => 'action', 'label' => 'Switch theme', 'icon' => 'sun-moon', 'hint' => 'light / dark', 'action' => 'theme'],
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(): array
    {
        $team = $this->team !== null;
        $cloud = Edition::current()->isCloud();

        return array_values(array_filter([
            $team ? self::page('fleet', 'Fleet', 'layout-grid', 'servers and apps', route('dashboard')) : null,
            self::page('deployments', 'Deployments', 'rocket', 'fleet-wide history', route('deployments.index')),
            $team ? self::page('releases', 'Releases', 'layers', "what's live, and rollbacks", route('releases.index')) : null,
            $team ? self::page('projects', 'Projects', 'folder', 'apps in groups', route('projects.index')) : null,
            $team && $this->user->can('update', $this->team) ? self::page('audit-log', 'Audit log', 'file-clock', 'who changed what', route('audit-log.index'), ['history', 'changes', 'export']) : null,
            $team ? self::page('settings', 'Settings', 'settings', 'general', route('settings.general'), ['general']) : null,
            $team ? self::page('team', 'Team', 'users', 'settings', route('teams.index'), ['members', 'roles', 'invite', 'access']) : null,
            $team ? self::page('git', 'Git', 'git-branch', 'settings', route('source-providers.index'), ['github', 'gitlab', 'bitbucket', 'repositories']) : null,
            $team ? self::page('cloud-providers', 'Cloud providers', 'cloud', 'settings', route('cloud-providers.index'), ['hetzner', 'digitalocean', 'vultr', 'linode']) : null,
            $team ? self::page('notifications', 'Notifications', 'bell', 'settings', route('settings.notifications'), ['alerts', 'email', 'slack']) : null,
            self::page('api-tokens', 'API tokens', 'key-round', 'settings', route('api-tokens.index'), ['keys']),
            $cloud ? self::page('billing', 'Billing', 'credit-card', $this->team === null ? Edition::Cloud->label() : app(BillingPresenter::class)->paletteHint($this->team), route('settings.billing'), ['plan', 'invoices', 'payment']) : null,
            self::page('profile', 'Profile', 'user', 'your account', route('profile.edit'), ['name', 'email']),
            self::page('security', 'Security', 'shield', 'your account', route('security.edit'), ['password', 'two-factor', 'sessions']),
        ]));
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return list<array<string, mixed>>
     */
    private function projects(Collection $applications): array
    {
        $counts = $applications->countBy(fn(Application $application): string => (string) $application->project_id);

        return app(TeamAccess::class)->projects($this->user, $this->team)->orderBy('name')->get()->map(function (Project $project) use ($counts): array {
            $apps = (int) $counts->get((string) $project->getKey(), 0);

            return [
                'id' => 'project:' . $project->getKey(),
                'kind' => 'project',
                'label' => $project->name,
                'hint' => $apps . ' ' . ($apps === 1 ? 'app' : 'apps'),
                'icon' => 'folder',
                'href' => route('projects.show', $project),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return list<array<string, mixed>>
     */
    private function applications(Collection $applications): array
    {
        $logs = $this->can(Ability::ViewApplicationLogs);
        $update = $this->can(Ability::UpdateApplication);
        $developer = $this->user->roleIn($this->team) === TeamRole::Developer;

        return $applications->map(function (Application $application) use ($logs, $update, $developer): array {
            $server = (string) app(RouteKeys::class)->server($application->server_id);

            return [
                'id' => 'app:' . $application->getKey(),
                'kind' => 'app',
                'label' => $application->name,
                'hint' => implode(' · ', [...$application->tags, 'on ' . $application->server?->name]),
                'icon' => 'layers',
                'href' => route('servers.applications.show', [$server, $application]),
                'keywords' => array_values(array_filter([...$application->tags, $application->server?->name, $application->project?->name])),
                'keys' => ['server' => $server, 'app' => (string) $application->getRouteKey()],
                'can' => [
                    'logs' => $logs,
                    'setup' => $update && (!$developer || $application->addedBy($this->user)),
                    'terminal' => $application->current_release !== null && $application->server instanceof Server && $application->server->status === ServerStatus::Active && $this->terminal($application->server, false),
                ],
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function servers(FleetSnapshot $fleet): array
    {
        return $fleet->servers()->map(function (Server $server) use ($fleet): array {
            $state = $fleet->state($server);
            $count = $server->applications->count();

            return [
                'id' => 'server:' . $server->getKey(),
                'kind' => 'server',
                'label' => $server->name,
                'hint' => implode(' · ', [...$server->tags, $state['key'] === 'healthy' ? $count . ' ' . ($count === 1 ? 'app' : 'apps') : strtolower($state['label'])]),
                'icon' => in_array($state['tone'], ['warning', 'destructive'], true) ? 'triangle-alert' : 'server',
                'href' => route('servers.show', $server),
                'keywords' => array_values($server->tags),
                'keys' => ['server' => (string) $server->getRouteKey()],
                'can' => [
                    'logs' => $this->user->can('update', $server),
                    'terminal' => $server->status === ServerStatus::Active && $this->terminal($server, true),
                    'root' => $server->status === ServerStatus::Active && $this->user->can('openRootTerminal', $server),
                ],
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function databases(): array
    {
        return app(TeamAccess::class)->databases($this->user, $this->team)->with('server')->orderBy('name')->get()->map(function (ManagedDatabase $database): array {
            $server = (string) app(RouteKeys::class)->server($database->server_id);
            $engine = self::ENGINES[$database->type->value] ?? $database->type->value;

            return [
                'id' => 'database:' . $database->getKey(),
                'kind' => 'database',
                'label' => $database->name,
                'hint' => $engine . ' on ' . $database->server?->name,
                'icon' => 'database',
                'href' => route('servers.databases.show', [$server, $database]),
                'keywords' => array_values(array_filter([$engine, $database->server?->name])),
                'keys' => ['server' => $server],
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rollback(): ?array
    {
        if ($this->team === null) {
            return null;
        }

        $latest = Deployment::query()
            ->whereIn('application_id', app(TeamAccess::class)->applications($this->user, $this->team)->select('id'))
            ->where('status', DeploymentStatus::Succeeded->value)
            ->whereNotNull('release')
            ->with('application')
            ->latest('finished_at')
            ->first();

        $application = $latest?->application;

        if (!$application instanceof Application || !$this->user->can('deploy', $application)) {
            return null;
        }

        $previous = app(RollbackHistory::class)->previous($application);

        if ($previous === null) {
            return null;
        }

        return self::action('rollback', 'Roll back ' . $application->name, 'undo-2', 'to ' . Formats::release($previous->release), route('servers.applications.releases.index', [app(RouteKeys::class)->server($application->server_id), $application, 'rollback' => $previous->release]));
    }

    private function terminal(Server $server, bool $root): bool
    {
        $app = $this->terminals[$server->getKey()] ??= $this->user->can('openTerminal', $server);

        return $app || ($root && $this->user->can('openRootTerminal', $server));
    }

    private function can(Ability $ability): bool
    {
        return $this->team !== null && $this->user->hasTeamAbility($this->team, $ability);
    }

    /**
     * @return array<string, mixed>
     */
    private static function action(string $id, string $label, string $icon, string $hint, string $href): array
    {
        return ['id' => 'action:' . $id, 'kind' => 'action', 'label' => $label, 'icon' => $icon, 'hint' => $hint, 'href' => $href];
    }

    /**
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    private static function page(string $id, string $label, string $icon, string $hint, string $href, array $keywords = []): array
    {
        return ['id' => 'page:' . $id, 'kind' => 'page', 'label' => $label, 'icon' => $icon, 'hint' => $hint, 'href' => $href, ...($keywords === [] ? [] : ['keywords' => $keywords])];
    }
}
