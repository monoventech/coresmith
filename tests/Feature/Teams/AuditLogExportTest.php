<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\GitHubApp;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\TeamInvitationNotification;
use App\Services\Teams\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;

function auditOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

/**
 * @return list<list<string>>
 */
function auditCsv(TestResponse $response): array
{
    $lines = array_values(array_filter(preg_split('/\r?\n/', (string) $response->streamedContent()), fn(string $line): bool => $line !== ''));

    return array_map(fn(string $line): array => str_getcsv($line, ',', '"', ''), $lines);
}

function auditExport(Team $team, ?string $period = null, ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? auditOwner($team))->get(route('settings.audit-log', array_filter(['period' => $period])));
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00'));
});

test("an owner downloads the team's audit log", function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    $owner = auditOwner($team);
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $gone = Server::factory()->for($team)->create(['name' => 'old-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_db']);
    $webhook = WebhookEndpoint::factory()->for($application)->create();
    $stranger = Server::factory()->create(['name' => 'not-ours']);

    activity()->performedOn($team)->causedBy($owner)->withProperties(['hostname' => 'deploy.acme.test'])->log('team_domain_added');
    activity()->performedOn($server)->log('server_provisioned');
    activity()->performedOn($gone)->causedBy($owner)->log('server_deleted');
    activity()->performedOn($application)->causedBy($owner)->log('deployment_started');
    activity()->performedOn($database)->causedBy($owner)->log('database_created');
    activity()->performedOn($webhook)->log('webhook_received');
    activity()->performedOn($stranger)->log('server_provisioned');
    $gone->delete();

    $response = auditExport($team)->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('coresmith-acme-audit-2026-10-18.csv');

    $rows = auditCsv($response);

    expect($rows[0])->toBe(AuditLog::HEADER)
        ->and(array_slice($rows, 1, 6))->toBe([
            ['2026-10-18T12:00:00Z', $owner->name, $owner->email, 'team_domain_added', 'team', 'Acme Labs', '{"hostname":"deploy.acme.test"}'],
            ['2026-10-18T12:00:00Z', 'Coresmith', '', 'server_provisioned', 'server', 'web-1', ''],
            ['2026-10-18T12:00:00Z', $owner->name, $owner->email, 'server_deleted', 'server', 'old-1', ''],
            ['2026-10-18T12:00:00Z', $owner->name, $owner->email, 'deployment_started', 'application', 'shop', ''],
            ['2026-10-18T12:00:00Z', $owner->name, $owner->email, 'database_created', 'database', 'shop_db', ''],
            ['2026-10-18T12:00:00Z', 'Coresmith', '', 'webhook_received', 'webhook', 'shop', ''],
        ])
        ->and(collect($rows)->pluck(5)->contains('not-ours'))->toBeFalse();
});

test("the team's cloud provider, git provider and github app events are in the export, removed ones too", function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    $owner = auditOwner($team);
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner production']);
    $source = SourceProvider::factory()->for($team)->create(['name' => 'monoven-labs']);
    $app = GitHubApp::factory()->for($team)->create(['name' => 'Coresmith Monoven']);
    $theirs = Team::factory()->create();
    $theirAccount = CloudProvider::factory()->for($theirs)->create(['name' => 'Their Hetzner']);
    $theirSource = SourceProvider::factory()->for($theirs)->create(['name' => 'their-labs']);
    $theirApp = GitHubApp::factory()->for($theirs)->create(['name' => 'Their app']);

    activity()->performedOn($account)->causedBy($owner)->withProperties(['provider' => 'Hetzner Cloud', 'name' => $account->name])->log('cloud_provider_connected');
    $account->delete();
    activity()->performedOn($account)->causedBy($owner)->withProperties(['provider' => 'Hetzner Cloud'])->log('cloud_provider_removed');
    activity()->performedOn($source)->withProperties(['installation_id' => '61842'])->log('github_installation_linked');
    $source->markUninstalled();
    activity()->performedOn($app)->causedBy($owner)->withProperties(['team_id' => $team->id, 'name' => $app->name])->log('github_app_registered');
    $this->actingAs($owner)->delete(route('github-apps.destroy', $app))->assertRedirect();
    activity()->performedOn($theirAccount)->withProperties(['provider' => 'Hetzner Cloud', 'name' => $theirAccount->name])->log('cloud_provider_connected');
    activity()->performedOn($theirSource)->log('github_installation_linked');
    activity()->performedOn($theirApp)->withProperties(['team_id' => $theirs->id, 'name' => $theirApp->name])->log('github_app_registered');
    $theirApp->delete();

    $rows = array_filter(array_slice(auditCsv(auditExport($team)->assertOk()), 1), fn(array $row): bool => $row[3] !== 'audit_log_exported');

    expect(array_map(fn(array $row): array => [$row[3], $row[4], $row[5]], $rows))->toBe([
        ['cloud_provider_connected', 'cloud_provider', 'Hetzner production'],
        ['cloud_provider_removed', 'cloud_provider', 'Hetzner production'],
        ['github_installation_linked', 'source_provider', 'monoven-labs'],
        ['github_installation_removed', 'source_provider', 'monoven-labs'],
        ['github_app_registered', 'github_app', 'Coresmith Monoven'],
        ['github_app_removed', 'github_app', 'Coresmith Monoven'],
    ]);
});

test('provider rows are escaped and redacted like the rest', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    $account = CloudProvider::factory()->for($team)->create(['name' => '=HYPERLINK("http://x")']);
    $source = SourceProvider::factory()->for($team)->create(['name' => '+1+1']);
    $app = GitHubApp::factory()->for($team)->create();

    activity()->performedOn($account)->withProperties(['command' => 'mysql --password=hunter2hunter2'])->log('cloud_provider_token_replaced');
    activity()->performedOn($source)->withProperties(['command' => 'mysql --password=hunter3hunter3'])->log('github_installation_linked');
    activity()->performedOn($app)->withProperties(['team_id' => $team->id, 'name' => '@SUM(A1)', 'command' => 'mysql --password=hunter4hunter4'])->log('github_app_removed');
    $app->delete();

    [$cloud, $git, $github] = array_slice(auditCsv(auditExport($team)->assertOk()), 1);

    expect($cloud[5])->toBe('\'=HYPERLINK("http://x")')
        ->and($cloud[6])->toContain('[REDACTED]')->not->toContain('hunter2hunter2')
        ->and($git[5])->toBe("'+1+1")
        ->and($git[6])->toContain('[REDACTED]')->not->toContain('hunter3hunter3')
        ->and($github[5])->toBe("'@SUM(A1)")
        ->and($github[6])->toContain('[REDACTED]')->not->toContain('hunter4hunter4');
});

test('the period limits the rows', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    $old = activity()->performedOn($team)->log('old_change');
    $old->forceFill(['created_at' => now()->subDays(100)])->save();
    activity()->performedOn($team)->log('recent_change');

    $events = fn(?string $period): array => array_column(array_slice(auditCsv(auditExport($team, $period)->assertOk()), 1), 3);

    expect($events('all'))->toContain('old_change', 'recent_change')
        ->and($events('90'))->not->toContain('old_change')
        ->and($events(null))->not->toContain('old_change')
        ->and($events('90'))->toContain('recent_change')
        ->and($events('365'))->toContain('old_change')
        ->and($events('30'))->toContain('recent_change');
});

test('an unknown period is refused', function (mixed $period) {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    $this->actingAs(auditOwner($team))->get(route('settings.audit-log') . '?' . http_build_query(['period' => $period]))
        ->assertRedirect()
        ->assertSessionHasErrors('period');
})->with(['a number of days not offered' => ['7'], 'a word' => ['forever'], 'a list' => [['30']]]);

test('a spreadsheet never runs what a cell says', function (string $name) {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    activity()->performedOn(Server::factory()->for($team)->create(['name' => $name]))->log('server_provisioned');

    $row = auditCsv(auditExport($team)->assertOk())[1];

    expect($row[5])->toBe("'{$name}");
})->with([
    'equals' => ['=HYPERLINK("http://x")'],
    'plus' => ['+1+1'],
    'minus' => ['-1+1'],
    'at' => ['@SUM(A1)'],
    'tab' => ["\tweb"],
    'carriage return' => ["\rweb"],
]);

test('plain cells are left alone', function () {
    expect(AuditLog::cell('web-1'))->toBe('web-1')
        ->and(AuditLog::cell(''))->toBe('')
        ->and(AuditLog::cell(null))->toBe('');
});

test('secrets in details are redacted', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    activity()->performedOn(Server::factory()->for($team)->create())->withProperties(['command' => 'mysql --password=hunter2hunter2'])->log('ssh_command_executed');

    $details = auditCsv(auditExport($team)->assertOk())[1][6];

    expect($details)->toContain('[REDACTED]')
        ->not->toContain('hunter2hunter2');
});

test('audit export needs business on cloud', function () {
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    $this->actingAs(auditOwner($pro))->from(route('teams.index'))->get(route('settings.audit-log'))
        ->assertRedirect(route('teams.index'))
        ->assertSessionHas('error', 'Audit log export is part of Business. Change the plan in Billing settings.');

    config(['coresmith.edition' => 'self-hosted']);
    $plain = Team::factory()->owned()->create(['slug' => 'plain']);

    auditExport($plain)->assertOk()->assertDownload('coresmith-plain-audit-2026-10-18.csv');
});

test('a read-only team cannot export, whatever plan it had', function () {
    $lapsed = Team::factory()->trialEnded()->owned()->create();

    $this->actingAs(auditOwner($lapsed))->from(route('teams.index'))->get(route('settings.audit-log'))
        ->assertRedirect(route('teams.index'))
        ->assertSessionHas('error', "This team's trial has ended, so it is read-only. Servers and apps keep running. Choose a plan in Billing settings to make changes again.");
});

test('deployers cannot export', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    $deployer = CloudBilling::member($team, TeamRole::Member);
    $deployer->forceFill(['current_team_id' => $team->getKey()])->save();

    auditExport($team, user: $deployer->fresh())->assertForbidden();
});

test('membership changes are recorded', function () {
    Notification::fake();
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    $owner = auditOwner($team);
    $newcomer = User::factory()->create(['email' => 'newcomer@example.test']);

    $this->actingAs($owner)->post(route('teams.members.store', $team), ['email' => $newcomer->email, 'role' => TeamRole::Member->value])->assertSessionHasNoErrors();

    $link = '';
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$link): bool {
        $link = (string) $notification->toMail($notifiable)->actionUrl;

        return true;
    });

    $this->actingAs($newcomer)->post(route('invitations.accept', Str::afterLast($link, '/')))->assertRedirect();
    $this->actingAs($owner)->put(route('teams.members.update', [$team, $newcomer]), ['role' => TeamRole::Admin->value])->assertSessionHasNoErrors();
    $this->actingAs($owner)->delete(route('teams.members.destroy', [$team, $newcomer]))->assertSessionHasNoErrors();

    $entries = Activity::query()->where('subject_id', $team->getKey())->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe(['member_invited', 'member_added', 'member_role_changed', 'member_removed'])
        ->and($entries->pluck('properties')->map->all()->all())->toBeInAnyKeyOrder([
            ['email' => 'newcomer@example.test', 'role' => 'member'],
            ['email' => 'newcomer@example.test', 'role' => 'member'],
            ['email' => 'newcomer@example.test', 'from' => 'member', 'to' => 'admin'],
            ['email' => 'newcomer@example.test'],
        ])
        ->and($entries->pluck('causer_id')->all())->toBe([$owner->getKey(), $newcomer->getKey(), $owner->getKey(), $owner->getKey()]);
});

test('an export is itself recorded', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    auditExport($team, '30')->assertOk();

    $entry = Activity::query()->where('description', 'audit_log_exported')->firstOrFail();

    expect($entry->properties->all())->toBe(['period' => '30'])
        ->and($entry->subject_id)->toBe($team->getKey())
        ->and($entry->causer_id)->toBe($team->owner_id);
});
