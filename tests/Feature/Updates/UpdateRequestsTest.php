<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\InstanceSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdatePaths;
use App\Services\Updates\UpdateRequest;
use App\Services\Updates\UpdateState;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->paths = updatesPanel();
    $this->owner = User::factory()->create();
    Team::factory()->create()->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
});

afterEach(function () {
    updatesCleanUp();
});

function updatesScheduled(string $name): Event
{
    return collect(app(Schedule::class)->events())->first(fn(Event $event): bool => $event->description === $name);
}

test('a request the update unit took is run once', function () {
    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', $this->owner->id, now()->toImmutable()));
    rename(app(UpdatePaths::class)->requestFile(), app(UpdatePaths::class)->takenRequestFile());

    $this->artisan('coresmith:update', ['--requested' => true])->assertExitCode(0);

    $status = app(UpdateState::class)->status();

    expect($status->state)->toBe(UpdateStatus::SUCCEEDED)
        ->and($status->trigger)->toBe('panel')
        ->and($status->requestedBy)->toBe($this->owner->id)
        ->and(is_file(app(UpdatePaths::class)->requestFile()))->toBeFalse()
        ->and(is_file(app(UpdatePaths::class)->takenRequestFile()))->toBeFalse();
});

test('a request is also taken straight from the panel\'s file', function () {
    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', $this->owner->id, now()->toImmutable()));

    $this->artisan('coresmith:update', ['--requested' => true])
        ->doesntExpectOutput('Update Coresmith from v0.1.0 to v0.1.1?')
        ->assertExitCode(0);

    expect(app(UpdateState::class)->status()->state)->toBe(UpdateStatus::SUCCEEDED)
        ->and(is_file(app(UpdatePaths::class)->requestFile()))->toBeFalse();
});

test('nothing requested is not an error', function () {
    $this->artisan('coresmith:update', ['--requested' => true])
        ->expectsOutput('No update was requested.')
        ->assertExitCode(0);
});

test('a recovery request recovers', function () {
    updatesInterrupted(fn(string $archive): array => ['built' => '0.1.1-a1b2c3d', 'maintenance' => true, 'backup' => $archive, 'migrating' => true, 'switched' => true], 'switch');
    app(ReleaseLayout::class)->activate(updatesRelease($this->paths['root'], '0.1.1', 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0'));
    app(UpdateState::class)->request(new UpdateRequest(null, 'recover', $this->owner->id, now()->toImmutable()));

    $this->artisan('coresmith:update', ['--requested' => true])->assertExitCode(0);

    expect(app(UpdateState::class)->status()->state)->toBe(UpdateStatus::ROLLED_BACK)
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce');
});

test('a request waits untaken while another update holds the lock', function () {
    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', $this->owner->id, now()->toImmutable()));
    rename(app(UpdatePaths::class)->requestFile(), app(UpdatePaths::class)->takenRequestFile());
    $lock = app(UpdateState::class)->acquireLock();

    try {
        $this->artisan('coresmith:update', ['--requested' => true])
            ->expectsOutputToContain('Another update is running.')
            ->assertExitCode(1);
    } finally {
        $lock->release();
    }

    expect(is_file(app(UpdatePaths::class)->takenRequestFile()))->toBeTrue()
        ->and(app(UpdateState::class)->status())->toBeNull();
});

test('a panel request refused before it starts is recorded as aborted', function () {
    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', $this->owner->id, now()->toImmutable()));
    unlink("{$this->paths['root']}/app");
    mkdir("{$this->paths['root']}/app");

    $this->artisan('coresmith:update', ['--requested' => true])->assertExitCode(1);

    expect(app(UpdateState::class)->status())
        ->state->toBe(UpdateStatus::ABORTED)
        ->trigger->toBe('panel')
        ->requestedBy->toBe($this->owner->id)
        ->toVersion->toBe('0.1.1')
        ->failedStep->toBe('preflight')
        ->message->toBe("This install doesn't use release directories, so it can't update itself. Run the installer for the version you want instead.")
        ->and(is_file(app(UpdatePaths::class)->requestFile()))->toBeFalse();
});

test('a request for a version that is not newer is refused before anything is built', function () {
    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.0'), 'panel', $this->owner->id, now()->toImmutable()));

    $this->artisan('coresmith:update', ['--requested' => true])->assertExitCode(1);

    expect(app(UpdateState::class)->status())
        ->state->toBe(UpdateStatus::ABORTED)
        ->failedStep->toBe('preflight')
        ->message->toBe('v0.1.0 is not newer than the running v0.1.0. Coresmith only updates forward.');
});

test('tonight\'s patch is requested only when the owner turned it on', function (?string $setting, Closure $setUp, string $output, ?string $requested) {
    InstanceSetting::put('auto_update', $setting);
    $setUp();

    $this->artisan('coresmith:update', ['--auto' => true])
        ->expectsOutput($output)
        ->assertExitCode(0);

    $request = app(UpdateState::class)->pendingRequest();

    expect($request === null ? null : "{$request->trigger} {$request->to}")->toBe($requested);
})->with([
    'off' => ['0', fn() => updatesFeed(['latest' => '0.1.1']), 'Automatic updates are off.', null],
    'never set' => [null, fn() => updatesFeed(['latest' => '0.1.1']), 'Automatic updates are off.', null],
    'a patch is out' => ['1', fn() => updatesFeed(['latest' => '0.1.1']), 'Requested the patch update to v0.1.1.', 'auto 0.1.1'],
    'only a minor is out' => ['1', fn() => updatesFeed(['latest' => '0.2.0']), 'No patch update to install.', null],
    'a request is waiting' => ['1', fn() => app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', null, now()->toImmutable())), 'An update is already under way.', 'panel 0.1.1'],
    'a run is going' => ['1', fn() => app(UpdateState::class)->save(UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse('0.1.1'), 'panel', null)), 'An update is already under way.', null],
]);

test('the schedule asks at four, and checks for interrupted updates every minute even in maintenance', function () {
    $auto = updatesScheduled('coresmith:auto-update');
    $recover = updatesScheduled('coresmith:recover-update');
    InstanceSetting::put('auto_update', '1');

    expect($auto->expression)->toBe('0 4 * * *')
        ->and($recover->expression)->toBe('* * * * *')
        ->and($recover->evenInMaintenanceMode)->toBeTrue()
        ->and($recover->withoutOverlapping)->toBeTrue()
        ->and($recover->expiresAt)->toBe(5)
        ->and($auto->filtersPass(app()))->toBeTrue()
        ->and($recover->filtersPass(app()))->toBeFalse();

    app(UpdateState::class)->save(UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse('0.1.1'), 'panel', null));
    $lock = app(UpdateState::class)->acquireLock();

    expect($recover->filtersPass(app()))->toBeFalse();

    $lock->release();

    expect($recover->filtersPass(app()))->toBeTrue();

    config(['coresmith.updates.enabled' => false]);

    expect($auto->filtersPass(app()))->toBeFalse()
        ->and($recover->filtersPass(app()))->toBeFalse();

    config(['coresmith.updates.enabled' => true]);
    InstanceSetting::put('auto_update', '0');

    expect($auto->filtersPass(app()))->toBeFalse();
});
