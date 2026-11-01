<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\InstanceSetting;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdatePaths;
use App\Services\Updates\UpdateRequest;
use App\Services\Updates\UpdateState;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

require_once __DIR__ . '/../Updates/UpdatesHelpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->paths = updatesUseTemporaryPaths();
    updatesInstall($this->paths['root'], '0.1.0');
    config([
        'coresmith.updates.enabled' => true,
        'coresmith.version' => '0.1.0',
        'coresmith.updates.check_url' => 'https://get.coresmith.io/v1/version',
    ]);
    updatesFeed(['latest' => '0.1.1', 'summary' => "Faster deploys.\nCalmer logs.", 'changelog_url' => 'https://github.com/monoven/coresmith/releases/tag/v0.1.1', 'released_at' => '2026-10-17']);
    $this->owner = updatesOwner();
});

afterEach(function () {
    updatesCleanUp();
});

function updatesChecked(): void
{
    app(UpdateChecker::class)->check();
}

function updatesRunning(string $step = 'dependencies'): UpdateStatus
{
    $status = UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse('0.1.1'), 'panel', null)->withStep($step);
    app(UpdateState::class)->save($status);

    return $status;
}

test('the card is told what is installed, what is available and what it may do', function () {
    updatesChecked();

    $this->actingAs($this->owner)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->where('updates.mode', 'self-hosted')
            ->where('updates.current', '0.1.0')
            ->where('updates.channel', 'stable')
            ->where('updates.available', ['version' => '0.1.1', 'summary' => "Faster deploys.\nCalmer logs.", 'notes_url' => 'https://github.com/monoven/coresmith/releases/tag/v0.1.1', 'released' => 'Oct 17'])
            ->where('updates.badge', ['label' => 'Update available', 'tone' => 'default'])
            ->where('updates.managed', true)
            ->where('updates.auto', false)
            ->where('updates.check.enabled', true)
            ->where('updates.run', null)
            ->where('updates.notices', [['tone' => 'muted', 'text' => "Signatures aren't checked yet: no Coresmith release key has been published."]])
            ->where('updates.status_href', route('settings.updates.status')));
});

test('the badge says what matters most', function (Closure $setUp, ?array $badge) {
    $setUp();

    $this->actingAs($this->owner)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('updates.badge', $badge));
})->with([
    'never checked' => [fn() => null, ['label' => 'Not checked yet', 'tone' => 'default']],
    'up to date' => [function () {
        updatesFeed(['latest' => '0.1.0']);
        updatesChecked();
    }, ['label' => 'Up to date', 'tone' => 'success']],
    'a check that failed' => [function () {
        updatesFeed('nope', 500);
        updatesChecked();
    }, ['label' => 'Check failed', 'tone' => 'warning']],
    'checks off' => [fn() => config(['coresmith.updates.check_disabled' => true]), ['label' => 'Checks off', 'tone' => 'default']],
    'an update running' => [function () {
        updatesChecked();
        test()->lock = app(UpdateState::class)->acquireLock();
        updatesRunning();
    }, ['label' => 'Updating', 'tone' => 'accent']],
    'a request waiting' => [fn() => app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', null, CarbonImmutable::now())), ['label' => 'Updating', 'tone' => 'accent']],
    'an update rolled back' => [function () {
        updatesChecked();
        app(UpdateState::class)->save(updatesRunning('migrate')->finished(UpdateStatus::ROLLED_BACK, 'x', 'migrate'));
    }, ['label' => 'Update failed', 'tone' => 'destructive']],
    'an update interrupted' => [function () {
        updatesChecked();
        updatesRunning('switch');
    }, ['label' => 'Update failed', 'tone' => 'destructive']],
]);

test('cloud and copies the installer did not make say so', function () {
    config(['coresmith.edition' => 'cloud']);

    $this->actingAs($this->owner)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->where('updates.mode', 'cloud')
            ->where('updates.line', 'Coresmith v0.1.0 · Coresmith Cloud · updates are managed for you')
            ->where('updates.badge', null)
            ->where('updates.notices', []));

    config(['coresmith.edition' => 'self-hosted', 'coresmith.updates.enabled' => false]);

    $this->actingAs($this->owner)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->where('updates.mode', 'unmanaged')
            ->where('updates.badge', null)
            ->where('updates.notices', [['tone' => 'muted', 'text' => "This copy of Coresmith wasn't installed by the Coresmith installer, so it can't update itself. Update it the way you installed it."]]));
});

test('the sub-line says when the panel last updated, or when it was installed', function () {
    InstanceSetting::put('installed_at', '2026-08-08T12:00:00+00:00');

    $this->actingAs($this->owner)->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('updates.line', 'Coresmith v0.1.0 · stable channel · installed Aug 8, 2026'));

    $this->travelTo('2026-10-18 04:00:41');
    app(UpdateState::class)->save(updatesRunning('health')->finished(UpdateStatus::SUCCEEDED, 'Done.'));

    $this->actingAs($this->owner)->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('updates.line', 'Coresmith v0.1.0 · stable channel · updated Oct 18, 4:00 AM'));
});

test('a withdrawn or unsupported version is warned about', function () {
    updatesFeed(['latest' => '0.1.1', 'minimum' => '0.1.1', 'withdrawn' => ['0.1.0']]);
    updatesChecked();
    InstanceSetting::put('installed_at', '2026-08-08T12:00:00+00:00');
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');

    $this->actingAs($this->owner)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('updates.notices', [
            ['tone' => 'warning', 'text' => 'v0.1.0 was withdrawn. Update to v0.1.1 as soon as you can.'],
            ['tone' => 'warning', 'text' => 'v0.1.0 is past its support window. Update to keep getting fixes.'],
        ]));
});

test('a failed check keeps the release it found and says why', function () {
    updatesChecked();
    updatesFeed('nope', 502);
    updatesChecked();
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');

    $this->actingAs($this->owner)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->where('updates.available.version', '0.1.1')
            ->where('updates.badge', ['label' => 'Update available', 'tone' => 'default'])
            ->where('updates.notices', [['tone' => 'muted', 'text' => 'The last check failed: The release feed at get.coresmith.io answered 502. Try again later.']]));
});

test('a deployer sees the version but not the run', function () {
    updatesChecked();
    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', null, CarbonImmutable::now()));

    $this->actingAs(updatesOwner(TeamRole::Member))
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page
            ->where('updates.current', '0.1.0')
            ->where('updates.badge', ['label' => 'Updating', 'tone' => 'accent'])
            ->where('updates.run', null));
});

test('an owner can request the update the card showed', function () {
    updatesChecked();

    $this->actingAs($this->owner)
        ->from(route('settings.general'))
        ->post(route('settings.updates.store'), ['to' => '0.1.1'])
        ->assertRedirect(route('settings.general'))
        ->assertSessionHas('success', 'Update requested. It starts in a few seconds.');

    $request = app(UpdateState::class)->pendingRequest();

    expect((string) $request->to)->toBe('0.1.1')
        ->and($request->trigger)->toBe('panel')
        ->and($request->requestedBy)->toBe((string) $this->owner->id);
});

test('a request for something other than what is on offer is refused', function () {
    updatesChecked();

    $this->actingAs($this->owner)->post(route('settings.updates.store'), ['to' => '0.1.2'])
        ->assertSessionHas('error', 'A newer check found v0.1.1. Review it, then update.');

    expect(app(UpdateState::class)->pendingRequest())->toBeNull();

    updatesFeed(['latest' => '0.1.0']);
    updatesChecked();

    $this->actingAs($this->owner)->post(route('settings.updates.store'), ['to' => '0.1.1'])
        ->assertSessionHas('error', "There's no update to install.");

    expect(app(UpdateState::class)->pendingRequest())->toBeNull();
});

test('a second request is refused while one is pending or running', function () {
    updatesChecked();
    $first = new UpdateRequest(Version::parse('0.1.1'), 'panel', 'first-owner', CarbonImmutable::now());
    app(UpdateState::class)->request($first);

    $this->actingAs($this->owner)->post(route('settings.updates.store'), ['to' => '0.1.1'])
        ->assertSessionHas('error', 'An update is already under way.');

    expect(app(UpdateState::class)->pendingRequest()->toArray())->toBe($first->toArray());

    app(UpdateState::class)->cancelRequest();
    $lock = app(UpdateState::class)->acquireLock();
    updatesRunning();

    try {
        $this->actingAs($this->owner)->post(route('settings.updates.store'), ['to' => '0.1.1'])
            ->assertSessionHas('error', 'An update is already under way.');
    } finally {
        $lock->release();
    }

    expect(app(UpdateState::class)->pendingRequest())->toBeNull();
});

test('an install without release directories cannot be asked to update', function () {
    updatesChecked();
    unlink("{$this->paths['root']}/app");
    mkdir("{$this->paths['root']}/app");

    $this->actingAs($this->owner)->post(route('settings.updates.store'), ['to' => '0.1.1'])
        ->assertSessionHas('error', "This install can't update itself yet: it doesn't use release directories.");

    expect(app(UpdateState::class)->pendingRequest())->toBeNull();
});

test('a pending request can be cancelled until the update unit takes it', function () {
    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', null, CarbonImmutable::now()));

    $this->actingAs($this->owner)->delete(route('settings.updates.cancel'))
        ->assertSessionHas('success', 'Request cancelled.');

    expect(app(UpdateState::class)->pendingRequest())->toBeNull();

    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', null, CarbonImmutable::now()));
    rename(app(UpdatePaths::class)->requestFile(), app(UpdatePaths::class)->takenRequestFile());

    $this->actingAs($this->owner)->delete(route('settings.updates.cancel'))
        ->assertSessionHas('error', "The update has already started, so it can't be cancelled.");
});

test('an interrupted update can be recovered from the card', function () {
    $this->actingAs($this->owner)->post(route('settings.updates.recover'))
        ->assertSessionHas('error', "There's nothing to recover.");

    updatesRunning('switch');

    $this->actingAs($this->owner)->post(route('settings.updates.recover'))
        ->assertSessionHas('success', 'Recovering. This takes about a minute.');

    expect(app(UpdateState::class)->pendingRequest())
        ->trigger->toBe('recover')
        ->to->toBeNull();
});

test('checking now reports what the feed said', function (Closure $setUp, string $key, string $message) {
    $setUp();

    $this->actingAs($this->owner)->post(route('settings.updates.check'))
        ->assertSessionHas($key, $message);
})->with([
    'available' => [fn() => null, 'success', 'Coresmith v0.1.1 is available.'],
    'up to date' => [fn() => updatesFeed(['latest' => '0.1.0']), 'success', "You're up to date."],
    'a failing feed' => [fn() => updatesFeed('nope', 500), 'error', 'The release feed at get.coresmith.io answered 500. Try again later.'],
]);

test('the automatic patch setting is saved', function () {
    $this->actingAs($this->owner)->put(route('settings.updates.auto'), ['on' => true])
        ->assertSessionHas('success', 'Saved.');

    expect(InstanceSetting::get('auto_update'))->toBe('1');

    $this->actingAs($this->owner)->put(route('settings.updates.auto'), ['on' => false]);

    expect(InstanceSetting::get('auto_update'))->toBe('0');
});

test('the status endpoint describes the run', function () {
    $this->travelTo('2026-11-01 04:00:00');
    $lock = app(UpdateState::class)->acquireLock();
    $status = updatesRunning();
    app(UpdateState::class)->appendLog($status->id, 'Fetching the release');
    app(UpdateState::class)->appendLog($status->id, 'Installing PHP dependencies');
    $this->travelTo('2026-11-01 04:01:05');

    try {
        $this->actingAs($this->owner)
            ->getJson(route('settings.updates.status'))
            ->assertOk()
            ->assertExactJson(['run' => [
                'id' => $status->id,
                'state' => 'running',
                'from' => '0.1.0',
                'to' => '0.1.1',
                'step' => 'dependencies',
                'label' => 'Installing PHP dependencies',
                'number' => 4,
                'total' => 12,
                'started' => 'today 04:00',
                'duration' => '1m 05s',
                'message' => null,
                'failed_label' => null,
                'log' => ['Fetching the release', 'Installing PHP dependencies'],
                'log_href' => route('settings.updates.log', $status->id),
                'stalled' => false,
            ]]);
    } finally {
        $lock->release();
    }
});

test('a request the update service has not taken for half a minute is stalled', function () {
    $this->travelTo('2026-11-01 04:00:00');
    app(UpdateState::class)->request(new UpdateRequest(Version::parse('0.1.1'), 'panel', null, CarbonImmutable::now()));

    $this->actingAs($this->owner)->getJson(route('settings.updates.status'))
        ->assertJsonPath('run.state', 'requested')
        ->assertJsonPath('run.stalled', false);

    $this->travelTo('2026-11-01 04:00:31');

    $this->actingAs($this->owner)->getJson(route('settings.updates.status'))
        ->assertJsonPath('run.stalled', true);

    rename(app(UpdatePaths::class)->requestFile(), app(UpdatePaths::class)->takenRequestFile());

    $this->actingAs($this->owner)->getJson(route('settings.updates.status'))
        ->assertJsonPath('run.state', 'starting')
        ->assertJsonPath('run.stalled', false);
});

test('the log of a run is read in full, and an unknown run is not found', function () {
    $status = updatesRunning();
    app(UpdateState::class)->appendLog($status->id, 'Fetching the release');

    $this->actingAs($this->owner)->getJson(route('settings.updates.log', $status->id))
        ->assertOk()
        ->assertExactJson(['lines' => ['Fetching the release']]);

    $this->actingAs($this->owner)->getJson(route('settings.updates.log', '01jbz0000000000000000000aa'))->assertNotFound();
});

test('only an instance owner reaches any of it', function (string $method, string $name, array $parameters) {
    $url = route($name, $parameters);

    foreach ([TeamRole::Admin, TeamRole::Member] as $role) {
        $this->actingAs(updatesOwner($role))->json($method, $url, ['to' => '0.1.1', 'on' => true])->assertForbidden();
    }

    auth()->logout();
    $this->json($method, $url)->assertUnauthorized();

    config(['coresmith.updates.enabled' => false]);
    $response = $this->actingAs($this->owner)->json($method, $url, ['to' => '0.1.1', 'on' => true]);

    $name === 'settings.updates.status' ? $response->assertOk() : $response->assertNotFound();
})->with([
    'status' => ['GET', 'settings.updates.status', []],
    'check' => ['POST', 'settings.updates.check', []],
    'store' => ['POST', 'settings.updates.store', []],
    'cancel' => ['DELETE', 'settings.updates.cancel', []],
    'recover' => ['POST', 'settings.updates.recover', []],
    'auto' => ['PUT', 'settings.updates.auto', []],
    'log' => ['GET', 'settings.updates.log', ['run' => '01jbz0000000000000000000aa']],
]);

test('checks and requests are rate limited', function () {
    expect(Route::getRoutes()->getByName('settings.updates.check')->gatherMiddleware())->toContain('throttle:6,1,update-check')
        ->and(Route::getRoutes()->getByName('settings.updates.store')->gatherMiddleware())->toContain('throttle:6,1,update-request');
});
