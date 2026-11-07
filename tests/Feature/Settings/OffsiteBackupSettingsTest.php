<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Jobs\BackupDatabase;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;

function offsiteSettingsOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function offsiteSettingsForm(array $overrides = []): array
{
    return [
        'endpoint' => null,
        'region' => 'eu-central-1',
        'bucket' => 'lab-backups',
        'prefix' => 'nightly',
        'access_key_id' => 'AKIAEXAMPLE0000000000',
        'secret_access_key' => 'an-e2e-secret-access-key',
        'path_style' => false,
        ...$overrides,
    ];
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00'));
});

test('an owner sets a destination and its keys stay sealed', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    $this->actingAs(offsiteSettingsOwner($team));

    $this->from(route('teams.index'))->put(route('settings.offsite-backups.update'), offsiteSettingsForm())
        ->assertRedirect(route('teams.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. Databases go to S3 every night at 02:30.');

    $destination = $team->fresh()->backupDestination;
    $raw = json_encode(DB::table('backup_destinations')->where('team_id', $team->getKey())->first());

    expect($destination?->bucket)->toBe('lab-backups')
        ->and($destination?->access_key_id)->toBe('AKIAEXAMPLE0000000000')
        ->and($destination?->secret_access_key)->toBe('an-e2e-secret-access-key')
        ->and($raw)->not->toContain('AKIAEXAMPLE0000000000')
        ->and($raw)->not->toContain('an-e2e-secret-access-key')
        ->and(json_encode($destination?->toArray()))->not->toContain('an-e2e-secret-access-key')
        ->and(Activity::query()->where('description', 'offsite_backups_configured')->first()?->properties->all())->toBe(['bucket' => 'lab-backups']);

    $props = null;

    $this->get(route('teams.index'))->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    expect($props['offsite'])->toBe([
        'endpoint' => null,
        'region' => 'eu-central-1',
        'bucket' => 'lab-backups',
        'prefix' => 'nightly',
        'path_style' => false,
        'last_run' => null,
        'failing' => 0,
    ])->and(json_encode($props))->not->toContain('AKIAEXAMPLE0000000000')
        ->and(json_encode($props))->not->toContain('an-e2e-secret-access-key');
});

test('a blank secret keeps the saved one', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    BackupDestination::factory()->for($team)->create(['secret_access_key' => 'the-original-secret-key']);

    $this->actingAs(offsiteSettingsOwner($team))
        ->put(route('settings.offsite-backups.update'), offsiteSettingsForm(['access_key_id' => 'AKIANEWKEY0000000000', 'secret_access_key' => '']))
        ->assertSessionHasNoErrors();

    $destination = $team->fresh()->backupDestination;

    expect($destination?->access_key_id)->toBe('AKIANEWKEY0000000000')
        ->and($destination?->secret_access_key)->toBe('the-original-secret-key')
        ->and(BackupDestination::query()->count())->toBe(1);
});

test('the first destination needs its secret', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    $this->actingAs(offsiteSettingsOwner($team))
        ->put(route('settings.offsite-backups.update'), offsiteSettingsForm(['secret_access_key' => '']))
        ->assertSessionHasErrors(['secret_access_key' => 'Enter the secret access key.']);
});

test('a destination that cannot work is refused with what to fix', function (array $input, string $field, string $message) {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    $this->actingAs(offsiteSettingsOwner($team))
        ->put(route('settings.offsite-backups.update'), offsiteSettingsForm($input))
        ->assertSessionHasErrors([$field => $message]);

    expect(BackupDestination::query()->count())->toBe(0);
})->with([
    'a plain http endpoint' => [['endpoint' => 'http://minio.example.com'], 'endpoint', 'Use an https:// address, like https://s3.eu-central-1.amazonaws.com.'],
    'an endpoint with credentials' => [['endpoint' => 'https://user:pass@minio.example.com'], 'endpoint', 'Use an https:// address, like https://s3.eu-central-1.amazonaws.com.'],
    'an endpoint that is not an address' => [['endpoint' => 'minio'], 'endpoint', 'Use an https:// address, like https://s3.eu-central-1.amazonaws.com.'],
    'a region with spaces' => [['region' => 'EU Central'], 'region', "Use the bucket's region, like eu-central-1."],
    'no region' => [['region' => ''], 'region', "Use the bucket's region, like eu-central-1."],
    'a bucket with capitals' => [['bucket' => 'Lab_Backups'], 'bucket', "That isn't a bucket name. Use lowercase letters, digits, dots and hyphens."],
    'a bucket too short' => [['bucket' => 'ab'], 'bucket', "That isn't a bucket name. Use lowercase letters, digits, dots and hyphens."],
    'a prefix climbing out' => [['prefix' => 'nightly/../other'], 'prefix', "Use letters, digits and ! _ . * ' ( ) / -, with no .. and no leading /."],
    'a prefix from the root' => [['prefix' => '/nightly'], 'prefix', "Use letters, digits and ! _ . * ' ( ) / -, with no .. and no leading /."],
    'a prefix with spaces' => [['prefix' => 'night ly'], 'prefix', "Use letters, digits and ! _ . * ' ( ) / -, with no .. and no leading /."],
    'no access key' => [['access_key_id' => ''], 'access_key_id', 'Enter the access key ID.'],
    'an access key too long' => [['access_key_id' => str_repeat('A', 129)], 'access_key_id', 'An access key ID is at most 128 characters.'],
    'a secret too long' => [['secret_access_key' => str_repeat('s', 257)], 'secret_access_key', 'A secret access key is at most 256 characters.'],
]);

test('pro cannot send backups to s3', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    $this->actingAs(offsiteSettingsOwner($team))->from(route('teams.index'))
        ->put(route('settings.offsite-backups.update'), offsiteSettingsForm())
        ->assertRedirect(route('teams.index'))
        ->assertSessionHas('error', 'Database backups to S3 are part of Business. Change the plan in Billing settings.');

    expect(BackupDestination::query()->count())->toBe(0);
});

test('deployers cannot change the destination', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    BackupDestination::factory()->for($team)->create();
    $deployer = CloudBilling::member($team, TeamRole::Member);
    $deployer->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($deployer->fresh());

    $this->put(route('settings.offsite-backups.update'), offsiteSettingsForm())->assertForbidden();
    $this->delete(route('settings.offsite-backups.destroy'))->assertForbidden();

    expect(BackupDestination::query()->count())->toBe(1);
});

test('a lapsed team can still remove its destination', function () {
    $team = Team::factory()->trialEnded()->owned()->create();
    BackupDestination::factory()->for($team)->create();

    $this->actingAs(offsiteSettingsOwner($team))->from(route('teams.index'))
        ->delete(route('settings.offsite-backups.destroy'))
        ->assertRedirect(route('teams.index'))
        ->assertSessionHas('success', 'Removed. Databases are no longer sent to S3.');

    expect(BackupDestination::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', 'offsite_backups_removed')->exists())->toBeTrue();
});

test('self-hosted teams can always send backups to s3', function () {
    config(['coresmith.edition' => 'self-hosted']);
    $team = Team::factory()->owned()->create();

    $this->actingAs(offsiteSettingsOwner($team))
        ->put(route('settings.offsite-backups.update'), offsiteSettingsForm(['endpoint' => 'https://minio.example.com', 'path_style' => true]))
        ->assertSessionHasNoErrors();

    expect($team->fresh()->backupDestination?->path_style)->toBeTrue();
});

test('the team tab says when the destination last ran and how many databases are failing', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    BackupDestination::factory()->for($team)->create(['last_run_at' => now()->subHours(2)]);
    $server = Server::factory()->for($team)->create();
    ManagedDatabase::factory()->for($team)->for($server)->create(['last_offsite_error' => 'The bucket refused the upload: 403. Check the bucket name, region and keys.']);
    ManagedDatabase::factory()->for($team)->for($server)->create(['last_offsite_at' => now()->subHours(2)]);

    $this->actingAs(offsiteSettingsOwner($team))->get(route('teams.index'))->assertInertia(fn($page) => $page
        ->where('offsite.last_run', 'today 10:00')
        ->where('offsite.failing', 1));
});

test('a team without a destination has none on its team tab', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    $this->actingAs(offsiteSettingsOwner($team))->get(route('teams.index'))->assertInertia(fn($page) => $page->where('offsite', null));
});

test('the database page says when it was last sent to s3', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    BackupDestination::factory()->for($team)->create();
    $server = Server::factory()->for($team)->create();
    $sent = ManagedDatabase::factory()->for($team)->for($server)->create(['last_offsite_at' => now()->subHours(2)]);
    $failing = ManagedDatabase::factory()->for($team)->for($server)->create(['last_offsite_error' => 'The bucket refused the upload: 403. Check the bucket name, region and keys.']);
    $this->actingAs(offsiteSettingsOwner($team));

    $this->get(route('servers.databases.show', [$server, $sent]))->assertInertia(fn($page) => $page->where('offsite', ['sent' => 'today 10:00', 'error' => null]));
    $this->get(route('servers.databases.show', [$server, $failing]))->assertInertia(fn($page) => $page->where('offsite', ['sent' => null, 'error' => 'The bucket refused the upload: 403. Check the bucket name, region and keys.']));

    $team->backupDestination()->delete();

    $this->get(route('servers.databases.show', [$server, $sent]))->assertInertia(fn($page) => $page->where('offsite', null));
});

test('a backup started by hand goes to s3 when the team sends them there', function () {
    Queue::fake();
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    $server = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create();
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $proServer = Server::factory()->for($pro)->create();
    $proDatabase = ManagedDatabase::factory()->for($pro)->for($proServer)->create();
    BackupDestination::factory()->for($team)->create();
    BackupDestination::factory()->for($pro)->create();

    $this->actingAs(offsiteSettingsOwner($team))->post(route('servers.databases.backup', [$server, $database]))->assertSessionHas('success', 'Backup started.');
    $this->actingAs(offsiteSettingsOwner($pro))->post(route('servers.databases.backup', [$proServer, $proDatabase]))->assertSessionHas('success', 'Backup started.');

    Queue::assertPushed(BackupDatabase::class, fn(BackupDatabase $job): bool => $job->database->is($database) && $job->offsite);
    Queue::assertPushed(BackupDatabase::class, fn(BackupDatabase $job): bool => $job->database->is($proDatabase) && !$job->offsite);
});

test('the database page states s3 facts only for databases that go to s3', function (Closure $team, DatabaseType $type, bool $facts) {
    $team = $team();
    BackupDestination::factory()->for($team)->create();
    $server = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['type' => $type, 'last_offsite_at' => now()->subHours(2)]);

    $this->actingAs(offsiteSettingsOwner($team))->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn($page) => $page->where('offsite', $facts ? ['sent' => 'today 10:00', 'error' => null] : null));
})->with([
    'postgresql on business' => [fn() => Team::factory()->subscribed(Plan::Business)->owned()->create(), DatabaseType::Postgresql, true],
    'mysql on business' => [fn() => Team::factory()->subscribed(Plan::Business)->owned()->create(), DatabaseType::Mysql, true],
    'redis on business' => [fn() => Team::factory()->subscribed(Plan::Business)->owned()->create(), DatabaseType::Redis, false],
    'postgresql on pro with a destination left over' => [fn() => Team::factory()->subscribed(Plan::Pro)->owned()->create(), DatabaseType::Postgresql, false],
    'postgresql on a lapsed team' => [fn() => Team::factory()->trialEnded()->owned()->create(), DatabaseType::Postgresql, false],
]);

test('a redis backup started by hand does not claim to go to s3', function () {
    Queue::fake();
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    BackupDestination::factory()->for($team)->create();
    $server = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['type' => DatabaseType::Redis]);

    $this->actingAs(offsiteSettingsOwner($team))->post(route('servers.databases.backup', [$server, $database]));

    Queue::assertPushed(BackupDatabase::class, fn(BackupDatabase $job): bool => $job->offsite === false);
});
