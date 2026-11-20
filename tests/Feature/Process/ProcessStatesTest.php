<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\CollectServerMetrics;
use App\Models\Application;
use App\Models\AppProcess;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ServerProcesses;
use App\Services\Process\ProcessStates;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Teams\TeamAccess;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeSSHTransport;

function processStatesBlock(string $unit, string $active = 'active', string $sub = 'running', int $restarts = 0, string $memory = '104857600', int $since = 1795212000): string
{
    return implode("\n", [
        "Id={$unit}.service",
        "ActiveState={$active}",
        "SubState={$sub}",
        "NRestarts={$restarts}",
        "MemoryCurrent={$memory}",
        "ActiveEnterTimestamp=@{$since}",
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00'));
});

test("systemctl's output becomes one reading per Coresmith unit, with no memory when systemd has none", function () {
    $output = implode("\n\n", [
        processStatesBlock('coresmith-app-shop-web', restarts: 2),
        processStatesBlock('coresmith-app-shop-queue-worker', active: 'failed', sub: 'failed', memory: '[not set]'),
        processStatesBlock('nginx'),
    ]);

    expect(ProcessStates::parse($output))->toBe([
        ['unit' => 'coresmith-app-shop-web', 'active' => 'active', 'sub' => 'running', 'restarts' => 2, 'memory' => 104857600, 'since' => 1795212000],
        ['unit' => 'coresmith-app-shop-queue-worker', 'active' => 'failed', 'sub' => 'failed', 'restarts' => 0, 'memory' => null, 'since' => 1795212000],
    ]);
});

test('output as systemd 255 prints it, properties in its own order, a readable start time and a unit it does not know, is read the same way', function () {
    $output = implode("\n", [
        'NRestarts=1',
        'MemoryCurrent=51326976',
        'Id=coresmith-app-shop-fpm.service',
        'LoadState=loaded',
        'ActiveState=active',
        'SubState=running',
        'ActiveEnterTimestamp=Tue 2026-11-17 23:42:12 UTC',
        '',
        'NRestarts=0',
        'MemoryCurrent=[not set]',
        'Id=coresmith-app-shop-web.service',
        'LoadState=not-found',
        'ActiveState=inactive',
        'SubState=dead',
        'ActiveEnterTimestamp=',
    ]);

    expect(ProcessStates::parse($output))->toBe([
        ['unit' => 'coresmith-app-shop-fpm', 'active' => 'active', 'sub' => 'running', 'restarts' => 1, 'memory' => 51326976, 'since' => CarbonImmutable::parse('2026-11-17 23:42:12 UTC')->getTimestamp()],
    ]);
});

test('each process is kept against its app, even when one app name starts like another, and a unit that is gone is dropped', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $shop = Application::factory()->for($server)->create(['slug' => 'shop']);
    $admin = Application::factory()->for($server)->create(['slug' => 'shop-admin']);
    $states = app(ProcessStates::class);

    $states->record($server, ProcessStates::parse(implode("\n\n", [
        processStatesBlock('coresmith-app-shop-web'),
        processStatesBlock('coresmith-app-shop-admin-web'),
        processStatesBlock('coresmith-app-shop-queue-worker'),
    ])));
    $states->record($server, ProcessStates::parse(implode("\n\n", [
        processStatesBlock('coresmith-app-shop-web'),
        processStatesBlock('coresmith-app-shop-admin-web'),
    ])));

    expect(AppProcess::query()->where('application_id', $shop->id)->pluck('process')->all())->toBe(['web'])
        ->and(AppProcess::query()->where('application_id', $admin->id)->pluck('process')->all())->toBe(['web']);
});

test('restarts are counted over the last day, starting from the first reading and through a counter a deploy starts again', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    Application::factory()->for($server)->create(['slug' => 'shop']);
    $states = app(ProcessStates::class);
    $read = fn(int $restarts) => $states->record($server, ProcessStates::parse(processStatesBlock('coresmith-app-shop-queue-worker', restarts: $restarts)));

    $read(5);
    $this->travel(1)->minutes();
    $read(8);
    $this->travel(1)->minutes();
    $read(1);

    expect(AppProcess::query()->sole()->restartsToday())->toBe(4);

    $this->travel(25)->hours();
    $read(1);

    expect(AppProcess::query()->sole()->restartsToday())->toBe(0);
});

test('the metrics run reads the processes with one more command', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    Application::factory()->for($server)->create(['slug' => 'shop']);
    $transport = (new FakeSSHTransport)->respondTo('systemctl show', processStatesBlock('coresmith-app-shop-web'));
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new CollectServerMetrics($server), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect(array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'systemctl show'))))->toHaveCount(1)
        ->and(AppProcess::query()->sole()->process)->toBe('web');
});

test('the server page lists processes with trouble first: a failed one, then one that keeps restarting, and an app put to sleep reads asleep', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'name' => 'web']);
    $shop = Application::factory()->for($team)->for($server)->create(['slug' => 'shop', 'name' => 'shop']);
    Application::factory()->for($team)->for($server)->create(['slug' => 'blog', 'name' => 'blog', 'power_state' => PowerState::Sleeping]);
    $states = app(ProcessStates::class);

    $states->record($server, ProcessStates::parse(implode("\n\n", [
        processStatesBlock('coresmith-app-shop-web', restarts: 0),
        processStatesBlock('coresmith-app-shop-queue-worker', restarts: 0),
        processStatesBlock('coresmith-app-shop-scheduler', active: 'failed', sub: 'failed', memory: '[not set]'),
        processStatesBlock('coresmith-app-blog-web', active: 'inactive', sub: 'dead', memory: '[not set]'),
    ])));
    $this->travel(1)->minutes();
    $states->record($server, ProcessStates::parse(implode("\n\n", [
        processStatesBlock('coresmith-app-shop-web', restarts: 0),
        processStatesBlock('coresmith-app-shop-queue-worker', restarts: 14),
        processStatesBlock('coresmith-app-shop-scheduler', active: 'failed', sub: 'failed', memory: '[not set]'),
        processStatesBlock('coresmith-app-blog-web', active: 'inactive', sub: 'dead', memory: '[not set]'),
    ])));

    $rows = app(ServerProcesses::class)->for($server, User::query()->findOrFail($team->owner_id));

    expect(array_map(fn(array $row): string => "{$row['app']} {$row['process']} {$row['state']['label']}", $rows))->toBe([
        'shop scheduler Failed',
        'shop queue-worker Restarted 14 times today',
        'blog web Asleep',
        'shop web Running',
    ])
        ->and($rows[0]['state']['tone'])->toBe('destructive')
        ->and($rows[1]['state']['tone'])->toBe('warning')
        ->and($rows[3]['memory'])->toBe("100\u{00A0}MB")
        ->and($rows[0]['memory'])->toBeNull()
        ->and($rows[3]['href'])->toBe(route('servers.applications.show', [$server, $shop]));

    $this->actingAs(User::query()->findOrFail($team->owner_id))->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('processes.0.process', 'scheduler')->where('processes', fn($processes) => count($processes) === 4));
});

test("someone who sees only some of a server's apps sees only those apps' processes", function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    $shop = Application::factory()->for($team)->for($server)->create(['slug' => 'shop', 'name' => 'shop']);
    Application::factory()->for($team)->for($server)->create(['slug' => 'vault', 'name' => 'vault']);
    $member = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);
    app(TeamAccess::class)->sync($team, $member->fresh(), false, [$server->id], [], [], [], [Application::query()->where('slug', 'vault')->value('id')]);

    app(ProcessStates::class)->record($server, ProcessStates::parse(implode("\n\n", [
        processStatesBlock('coresmith-app-shop-web'),
        processStatesBlock('coresmith-app-vault-web'),
    ])));

    expect(array_column(app(ServerProcesses::class)->for($server, $member->fresh()), 'app'))->toBe([$shop->name]);
});
