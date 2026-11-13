<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\UnderTheHood;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{Team, User, Server}
 */
function underTheHoodServer(array $attributes = []): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);

    return [$team, $owner->fresh(), Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'os_name' => 'ubuntu', 'audit_logging_enabled' => false, ...$attributes])];
}

test('what Coresmith runs here lists each app with its user, ports, folder and processes', function () {
    [$team, $owner, $server] = underTheHoodServer(['meta' => ['caddy_admin' => 'socket']]);
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'slug' => 'shop', 'type' => ApplicationType::Php, 'framework' => 'laravel', 'base_port' => 8010, 'ssr_port' => 13714, 'system_user' => 'coresmith-app-shop']);

    $this->actingAs($owner)->get(route('servers.under-the-hood', $server))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->component('servers/UnderTheHood')
            ->where('server.href', route('servers.show', $server))
            ->where('server.hardening', route('servers.hardening', $server))
            ->where('apps.0.href', route('servers.applications.show', [$server, $shop]))
            ->where('apps.0.name', 'shop')
            ->where('apps.0.user', 'coresmith-app-shop')
            ->where('apps.0.ports', '8010–8019, 13714')
            ->where('apps.0.folder', '/var/coresmith/apps/shop')
            ->where('apps.0.processes', fn($processes): bool => collect($processes)->contains('coresmith-app-shop-fpm'))
            ->where('services.0.detail', 'Serves every app over HTTPS and renews its certificates. Coresmith changes its routes through the admin socket at /run/caddy-admin/admin.sock, which only root and caddy can open.')
            ->where('services', fn($services): bool => collect($services)->pluck('name')->contains('PHP-FPM'))
            ->where('isolation', UnderTheHood::ISOLATION)
            ->where('files', fn($files): bool => collect($files)->pluck('path')->all() === [
                '/var/coresmith/apps',
                '/etc/ssh/sshd_config.d/00-coresmith.conf',
                '/etc/fail2ban/jail.d/coresmith-ssh.conf',
                '/etc/sysctl.d/99-coresmith-hardening.conf',
                '/etc/apt/apt.conf.d/20auto-upgrades',
            ]));
});

test('on Coresmith\'s own machine the SSH settings are the owner\'s, so they are not listed, and the Red Hat family names its update config', function () {
    [, $owner, $server] = underTheHoodServer(['runs_coresmith' => true, 'os_name' => 'rocky', 'audit_logging_enabled' => true]);

    $this->actingAs($owner)->get(route('servers.under-the-hood', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('files', fn($files): bool => !collect($files)->pluck('path')->contains('/etc/ssh/sshd_config.d/00-coresmith.conf')
                && collect($files)->pluck('path')->contains('/etc/dnf/automatic.conf')
                && collect($files)->pluck('path')->contains('/etc/audit/rules.d/coresmith.rules'))
            ->where('services', fn($services): bool => collect($services)->pluck('name')->contains('firewalld') && !collect($services)->pluck('name')->contains('PHP-FPM')));
});

test('a server still running the shared Redis says its database credentials are per app except for it', function () {
    [, $owner, $server] = underTheHoodServer(['meta' => ['installed_databases' => ['redis']]]);

    $this->actingAs($owner)->get(route('servers.under-the-hood', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('isolation', fn($layers): bool => collect($layers)->contains(UnderTheHood::CREDENTIALS_EXCEPT_SHARED_REDIS) && !collect($layers)->contains(UnderTheHood::CREDENTIALS) && count($layers) === 9)
            ->where('services', fn($services): bool => collect($services)->pluck('name')->contains('Redis')));
});

test('a developer on a shared server sees only its own apps there', function () {
    [$team, $owner, $server] = underTheHoodServer();
    $developer = User::factory()->create();
    $team->members()->attach($developer, ['role' => TeamRole::Developer->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($team, $developer->fresh(), $server);
    Application::factory()->for($team)->for($server)->create(['name' => 'theirs', 'created_by' => $owner->id]);
    Application::factory()->for($team)->for($server)->create(['name' => 'mine', 'created_by' => $developer->id]);

    $this->actingAs($developer->fresh())->get(route('servers.under-the-hood', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('apps', fn($apps): bool => collect($apps)->pluck('name')->all() === ['mine']));
});
