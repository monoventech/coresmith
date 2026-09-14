<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\BackUpPanel;
use App\Models\InstanceSetting;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

function generalSettingsUser(TeamRole $role): User
{
    $user = User::factory()->create();
    Team::factory()->create()->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function generalSettingsArchive(string $name, CarbonImmutable $modified, bool $valid = true): string
{
    $directory = storage_path('app/private/backups');
    File::ensureDirectoryExists($directory);
    $path = $directory . '/' . $name;

    if ($valid) {
        $archive = new PharData(substr($path, 0, -3));
        $archive->addFromString('database.sqlite', str_repeat('x', 4096));
        $archive->compress(Phar::GZ);
        unset($archive);
        File::delete(substr($path, 0, -3));
    } else {
        file_put_contents($path, 'not an archive');
    }

    touch($path, $modified->getTimestamp());

    return $path;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
    $this->settingsStorage = realpath(sys_get_temp_dir()) . '/coresmith-settings-' . Str::lower(Str::random(10));
    File::ensureDirectoryExists($this->settingsStorage . '/app/private');
    $this->app->useStoragePath($this->settingsStorage);
});

afterEach(function () {
    File::deleteDirectory($this->settingsStorage);
});

test('the general settings page shows the instance as configured', function () {
    config(['coresmith.version' => '0.3.1']);
    InstanceSetting::put('instance_name', 'Monoven Ops');
    InstanceSetting::put('installed_at', '2026-08-08T12:00:00+00:00');
    InstanceSetting::put('timezone', 'Europe/London');
    InstanceSetting::put('notification_email', 'ops@example.com');

    $this->actingAs(generalSettingsUser(TeamRole::Owner))
        ->get(route('settings.general'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('settings/General')
            ->where('instance', [
                'name' => 'Monoven Ops',
                'version' => '0.3.1',
                'installed' => 'Aug 8, 2026',
                'timezone' => 'Europe/London',
                'notification_email' => 'ops@example.com',
            ])
            ->where('backups', ['last' => null, 'keep' => 14])
            ->where('timezones', fn($timezones) => $timezones->contains('Europe/London') && $timezones->contains('UTC'))
            ->where('can.manageInstance', true)
            ->where('can.reachServers', true));
});

test('an unconfigured instance falls back to the application defaults', function () {
    config(['app.name' => 'Coresmith', 'app.timezone' => 'UTC']);

    $this->actingAs(generalSettingsUser(TeamRole::Member))
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->where('instance.name', 'Coresmith')
            ->where('instance.installed', null)
            ->where('instance.timezone', 'UTC')
            ->where('instance.notification_email', null)
            ->where('can.manageInstance', false)
            ->where('can.reachServers', false)
            ->where('owns_team', false));
});

test('the last backup is described by when it ran, its size and whether it verifies', function () {
    generalSettingsArchive('coresmith-20260912-040000.tar.gz', CarbonImmutable::parse('2026-09-12 04:00:00'));
    $latest = generalSettingsArchive('coresmith-20260913-040000.tar.gz', CarbonImmutable::parse('2026-09-13 04:00:12'));

    $this->actingAs(generalSettingsUser(TeamRole::Owner))
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->where('backups.last.when', 'today 04:00')
            ->where('backups.last.size', Number::fileSize(filesize($latest), precision: 1))
            ->where('backups.last.verified', true));
});

test('a backup from an earlier day is dated and a corrupt one is not verified', function () {
    generalSettingsArchive('coresmith-20260910-040000.tar.gz', CarbonImmutable::parse('2026-09-10 04:00:00'), valid: false);

    $this->actingAs(generalSettingsUser(TeamRole::Owner))
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->where('backups.last.when', 'Sep 10, 04:00')
            ->where('backups.last.verified', false));
});

test('a user who owns a team is told so', function () {
    $user = generalSettingsUser(TeamRole::Member);
    Team::factory()->create(['owner_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('owns_team', true));
});

test('the users ssh keys are listed newest first with their type', function () {
    $user = generalSettingsUser(TeamRole::Member);
    $keys = [
        ['ssh-ed25519 AAAAC3Nz', 'laptop', now()->subDays(3)],
        ['ssh-rsa AAAAB3Nz', 'old desktop', now()->subDays(2)],
        ['ecdsa-sha2-nistp256 AAAAE2Vj', 'yubikey', now()->subDay()],
        ['sk-ssh-ed25519@openssh.com AAAAGnNr', 'security key', now()],
    ];

    foreach ($keys as [$publicKey, $name, $created]) {
        SshKey::factory()->for($user)->create(['public_key' => $publicKey, 'name' => $name, 'fingerprint' => 'SHA256:' . $name, 'created_at' => $created]);
    }

    SshKey::factory()->create(['name' => 'someone else']);

    $this->actingAs($user)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->has('keys', 4)
            ->where('keys', fn($listed) => $listed->map(fn($key) => [$key['name'], $key['type']])->all() === [
                ['security key', 'key'],
                ['yubikey', 'ecdsa'],
                ['old desktop', 'rsa'],
                ['laptop', 'ed25519'],
            ])
            ->where('keys.3.fingerprint', 'SHA256:laptop')
            ->where('keys.3.added', 'Sep 2026'));
});

test('a team owner can rename the instance and set its timezone and notification email', function () {
    $this->actingAs(generalSettingsUser(TeamRole::Owner))
        ->from(route('settings.general'))
        ->put(route('settings.instance.update'), [
            'instance_name' => 'Monoven Ops',
            'timezone' => 'Europe/Lisbon',
            'notification_email' => 'ops@example.com',
        ])
        ->assertRedirect(route('settings.general'))
        ->assertSessionHas('success', 'Saved.');

    expect(InstanceSetting::get('instance_name'))->toBe('Monoven Ops')
        ->and(InstanceSetting::get('timezone'))->toBe('Europe/Lisbon')
        ->and(InstanceSetting::get('notification_email'))->toBe('ops@example.com');
});

test('an update may change one setting and leave the rest alone', function () {
    InstanceSetting::put('instance_name', 'Monoven Ops');
    InstanceSetting::put('notification_email', 'ops@example.com');

    $this->actingAs(generalSettingsUser(TeamRole::Owner))
        ->put(route('settings.instance.update'), ['notification_email' => null])
        ->assertSessionHasNoErrors();

    expect(InstanceSetting::get('instance_name'))->toBe('Monoven Ops')
        ->and(InstanceSetting::get('notification_email'))->toBeNull();
});

test('only a team owner can change instance settings', function (TeamRole $role) {
    InstanceSetting::put('instance_name', 'Before');

    $this->actingAs(generalSettingsUser($role))
        ->put(route('settings.instance.update'), ['instance_name' => 'After'])
        ->assertForbidden();

    expect(InstanceSetting::get('instance_name'))->toBe('Before');
})->with([TeamRole::Admin, TeamRole::Member]);

test('owning a team through its owner column is not enough without the owner role', function () {
    $user = generalSettingsUser(TeamRole::Admin);
    Team::factory()->create(['owner_id' => $user->id]);

    $this->actingAs($user)
        ->put(route('settings.instance.update'), ['instance_name' => 'After'])
        ->assertForbidden();
});

test('instance settings are validated', function (array $payload, string $field) {
    $this->actingAs(generalSettingsUser(TeamRole::Owner))
        ->put(route('settings.instance.update'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'an empty name' => [['instance_name' => ''], 'instance_name'],
    'a long name' => [['instance_name' => str_repeat('a', 61)], 'instance_name'],
    'an unknown timezone' => [['timezone' => 'Mars/Olympus_Mons'], 'timezone'],
    'an empty timezone' => [['timezone' => ''], 'timezone'],
    'a malformed email' => [['notification_email' => 'not-an-email'], 'notification_email'],
]);

test('a team owner can take a backup on demand', function () {
    Queue::fake();

    $this->actingAs(generalSettingsUser(TeamRole::Owner))
        ->from(route('settings.general'))
        ->post(route('settings.backups.store'))
        ->assertRedirect(route('settings.general'))
        ->assertSessionHas('success', 'Backing up. The database and environment are archived, the last 14 are kept.');

    Queue::assertPushed(BackUpPanel::class, 1);
});

test('only a team owner can take a backup', function (TeamRole $role) {
    Queue::fake();

    $this->actingAs(generalSettingsUser($role))
        ->post(route('settings.backups.store'))
        ->assertForbidden();

    Queue::assertNothingPushed();
})->with([TeamRole::Admin, TeamRole::Member]);

test('on demand backups are rate limited', function () {
    expect(Route::getRoutes()->getByName('settings.backups.store')->gatherMiddleware())->toContain('throttle:6,1');
});

test('general settings require authentication', function () {
    $this->get(route('settings.general'))->assertRedirect(route('login'));
    $this->put(route('settings.instance.update'), ['instance_name' => 'x'])->assertRedirect(route('login'));
    $this->post(route('settings.backups.store'))->assertRedirect(route('login'));
});
