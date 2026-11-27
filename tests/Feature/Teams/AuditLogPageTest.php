<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CommandPalette;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CloudBilling;

function auditPageTeam(): Team
{
    return Team::factory()->subscribed(Plan::Business)->owned()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
}

function auditPageOwner(Team $team): User
{
    $owner = User::query()->findOrFail($team->owner_id);
    $owner->forceFill(['current_team_id' => $team->getKey()])->save();

    return $owner->fresh();
}

function auditPageMember(Team $team, TeamRole $role, string $name = 'Grace Hopper'): User
{
    $user = CloudBilling::member($team, $role);
    $user->forceFill(['name' => $name, 'current_team_id' => $team->getKey()])->save();

    return $user->fresh();
}

function auditPageAt(string $description, mixed $subject, ?User $by = null, array $properties = [], int $minutesAgo = 0): void
{
    $activity = activity()->performedOn($subject)->withProperties($properties);

    if ($by !== null) {
        $activity->causedBy($by);
    }

    $logged = $activity->log($description);
    $logged->forceFill(['created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo)])->save();
}

function auditPageEvents(User $user, array $query = []): array
{
    $events = [];

    test()->actingAs($user)->get(route('audit-log.index', $query))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$events) {
        $page->component('audit-log/Index');
        $events = $page->toArray()['props']['events'];
    });

    return $events;
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-11-27 12:00:00'));
});

test('an owner reads the audit log newest first: when, who, what happened and to which thing', function () {
    $team = auditPageTeam();
    $owner = auditPageOwner($team);
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'orders']);

    auditPageAt('server_provisioned', $server, minutesAgo: 90);
    auditPageAt('application_created', $application, $owner, minutesAgo: 60);
    auditPageAt('database_created', $database, $owner, minutesAgo: 30);
    auditPageAt('terminal_opened', $server, $owner, ['mode' => 'root', 'app' => null], minutesAgo: 5);
    auditPageAt('server_provisioned', Server::factory()->create(['name' => 'not-ours']));

    $events = auditPageEvents($owner);

    expect(array_column($events, 'event'))->toBe(['Terminal opened', 'Database created', 'App created', 'Server provisioned'])
        ->and($events[0])->toMatchArray([
            'when' => 'today 11:55',
            'full' => 'Nov 27, 2026 at 11:55 UTC',
            'actor' => ['name' => $owner->name, 'email' => $owner->email],
            'subject' => ['kind' => 'Server', 'name' => 'web-1', 'href' => route('servers.show', $server)],
            'details' => [['key' => 'app', 'value' => ''], ['key' => 'mode', 'value' => 'root']],
        ])
        ->and($events[2]['subject'])->toBe(['kind' => 'App', 'name' => 'shop', 'href' => route('servers.applications.show', [$server, $application])])
        ->and($events[3]['actor'])->toBe(['name' => 'Coresmith', 'email' => null]);
});

test("coresmith's own ssh commands stay out of the list until asked for", function () {
    $team = auditPageTeam();
    $owner = auditPageOwner($team);
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    auditPageAt(SSHConnection::COMMAND_ACTIVITY, $server, properties: ['command' => 'systemctl reload caddy', 'exit_code' => 0, 'output_length' => 0], minutesAgo: 2);
    auditPageAt('server_rebooted', $server, $owner, minutesAgo: 1);

    expect(array_column(auditPageEvents($owner), 'event'))->toBe(['Server rebooted'])
        ->and(array_column(auditPageEvents($owner, ['what' => 'commands']), 'event'))->toBe(['SSH command executed'])
        ->and(auditPageEvents($owner, ['what' => 'commands'])[0]['details'])->toBe([['key' => 'command', 'value' => 'systemctl reload caddy'], ['key' => 'exit code', 'value' => '0'], ['key' => 'output length', 'value' => '0']])
        ->and(array_column(auditPageEvents($owner, ['what' => 'everything']), 'event'))->toBe(['Server rebooted', 'SSH command executed']);
});

test('the list narrows to one person, to coresmith itself, to a kind of thing and to a period', function () {
    $team = auditPageTeam();
    $owner = auditPageOwner($team);
    $grace = auditPageMember($team, TeamRole::Admin);
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);
    $account = CloudProvider::factory()->for($team)->create(['name' => 'hetzner-main']);

    auditPageAt('server_rebooted', $server, $owner, minutesAgo: 60 * 24 * 10);
    auditPageAt('application_created', $application, $grace, minutesAgo: 60);
    auditPageAt('server_provisioned', $server, minutesAgo: 30);
    auditPageAt('cloud_provider_connected', $account, $owner, minutesAgo: 10);

    expect(array_column(auditPageEvents($owner, ['who' => $grace->id]), 'event'))->toBe(['App created'])
        ->and(array_column(auditPageEvents($owner, ['who' => 'coresmith']), 'event'))->toBe(['Server provisioned'])
        ->and(array_column(auditPageEvents($owner, ['what' => 'servers']), 'event'))->toBe(['Server provisioned', 'Server rebooted'])
        ->and(array_column(auditPageEvents($owner, ['what' => 'team']), 'event'))->toBe(['Cloud provider connected'])
        ->and(array_column(auditPageEvents($owner, ['period' => '7']), 'event'))->toBe(['Cloud provider connected', 'Server provisioned', 'App created']);

    $this->actingAs($owner)->get(route('audit-log.index', ['who' => $grace->id, 'what' => 'apps', 'period' => '30']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('filters', ['period' => '30', 'who' => $grace->id, 'what' => 'apps'])
            ->where('people', fn($people): bool => collect($people)->pluck('label', 'value')->sortKeys()->all() === collect([$grace->id => 'Grace Hopper', $owner->id => $owner->name])->sortKeys()->all())
            ->where('export.href', route('audit-log.export', ['period' => '30', 'who' => $grace->id, 'what' => 'apps'])));
});

test('a filter it does not know falls back to the default instead of failing the page', function () {
    $team = auditPageTeam();
    $owner = auditPageOwner($team);

    $this->actingAs($owner)->get(route('audit-log.index', ['period' => '12', 'who' => 'nobody', 'what' => 'cats']))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('filters', ['period' => '90', 'who' => 'anyone', 'what' => 'changes']));
});

test('fifty events a page, with older and newer links that keep the filters', function () {
    $team = auditPageTeam();
    $owner = auditPageOwner($team);
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    foreach (range(1, 55) as $index) {
        auditPageAt('server_rebooted', $server, $owner, minutesAgo: $index);
    }

    $this->actingAs($owner)->get(route('audit-log.index', ['what' => 'servers']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->has('events', 50)
            ->where('pages.newer', null)
            ->where('pages.older', route('audit-log.index', ['what' => 'servers', 'page' => 2])));

    $this->actingAs($owner)->get(route('audit-log.index', ['what' => 'servers', 'page' => 2]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->has('events', 5)
            ->where('events.4.when', 'today 11:05')
            ->where('pages.newer', route('audit-log.index', ['what' => 'servers', 'page' => 1]))
            ->where('pages.older', null));

    $this->actingAs($owner)->get(route('audit-log.index', ['what' => 'servers', 'page' => 9]))
        ->assertRedirect(route('audit-log.index', ['what' => 'servers']));
});

test('a removed thing keeps its name but loses its link, and secrets in details are redacted', function () {
    $team = auditPageTeam();
    $owner = auditPageOwner($team);
    $gone = Server::factory()->for($team)->create(['name' => 'old-1']);

    auditPageAt('server_deleted', $gone, $owner, ['note' => 'password=hunter2hunter2 left']);
    $gone->delete();

    $event = auditPageEvents($owner)[0];

    expect($event['subject'])->toBe(['kind' => 'Server', 'name' => 'old-1', 'href' => null])
        ->and($event['details'][0]['value'])->toContain('[REDACTED]')
        ->and($event['details'][0]['value'])->not->toContain('hunter2hunter2');
});

test('only owners and admins have an audit log, and only they see it in the navigation and search', function (TeamRole $role, bool $allowed) {
    $team = auditPageTeam();
    $user = $role === TeamRole::Owner ? auditPageOwner($team) : auditPageMember($team, $role);

    $this->actingAs($user)->get(route('audit-log.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn(AssertableInertia $page) => $page->where('panel.can.auditLog', $allowed));

    expect(collect((new CommandPalette($user, $team))->items())->firstWhere('id', 'page:audit-log') !== null)->toBe($allowed);
})->with([
    'an owner' => [TeamRole::Owner, true],
    'an admin' => [TeamRole::Admin, true],
    'a developer' => [TeamRole::Developer, false],
    'a deployer' => [TeamRole::Member, false],
]);

test('the export keeps the filters on the page', function () {
    $team = auditPageTeam();
    $owner = auditPageOwner($team);
    $grace = auditPageMember($team, TeamRole::Admin);
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    auditPageAt('server_rebooted', $server, $owner, minutesAgo: 20);
    auditPageAt('server_rebooted', $server, $grace, minutesAgo: 10);
    auditPageAt(SSHConnection::COMMAND_ACTIVITY, $server, properties: ['command' => 'uptime'], minutesAgo: 5);

    $csv = fn(array $query): array => array_values(array_filter(preg_split('/\r?\n/', (string) $this->actingAs($owner)->get(route('audit-log.export', $query))->assertOk()->streamedContent()), fn(string $line): bool => $line !== ''));

    expect($csv(['who' => $grace->id]))->toHaveCount(2)
        ->and($csv(['what' => 'servers']))->toHaveCount(3)
        ->and($csv(['what' => 'commands']))->toHaveCount(2)
        ->and(implode("\n", $csv([])))->not->toContain('ssh_command_executed')
        ->and(implode("\n", $csv(['what' => 'everything'])))->toContain('ssh_command_executed');
});

test('the export button names what the plan lacks on cloud, and works everywhere self-hosted', function () {
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    $this->actingAs(auditPageOwner($pro))->get(route('audit-log.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('export.href', null)->where('export.note', fn($note): bool => is_string($note) && str_contains($note, 'Business')));

    config(['coresmith.edition' => 'self-hosted']);
    $plain = Team::factory()->owned()->create(['slug' => 'plain']);

    $this->actingAs(auditPageOwner($plain))->get(route('audit-log.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('export.note', null)->where('export.href', route('audit-log.export', ['period' => '90'])));
});

test('the old export address sends you to the new one, keeping the period and everything it used to export', function () {
    $team = auditPageTeam();

    $this->actingAs(auditPageOwner($team))->get('/settings/team/audit-log?period=30')
        ->assertRedirect(route('audit-log.export', ['period' => '30', 'what' => 'everything']));
});

test('the audit log has its own place in the trail and no longer sits in team settings', function () {
    $team = auditPageTeam();
    $owner = auditPageOwner($team);

    $this->actingAs($owner)->get(route('audit-log.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('crumbs', [
            ['label' => 'Acme Labs', 'href' => route('dashboard'), 'mono' => false, 'switch' => null],
            ['label' => 'Audit log', 'href' => null, 'mono' => false, 'switch' => null],
        ]));
});
