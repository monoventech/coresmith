<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\StartSession;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Sessions\EndedSessions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function refreshSessionSignedIn(?User $user = null): string
{
    $store = app('session')->driver();
    $store->setId(null);
    $store->start();

    if ($user !== null) {
        $store->put(Auth::guard('web')->getName(), $user->getKey());
    }

    $store->save();

    return $store->getId();
}

function refreshSessionStored(string $id): Store
{
    $store = new Store(config('session.cookie'), app('session')->driver()->getHandler(), $id, config('session.serialization'));
    $store->start();

    return $store;
}

function refreshSessionRequest(string $id, string $method = 'GET', bool $refresh = true, ?User $user = null): Request
{
    $request = Request::create('/dashboard', $method, cookies: [config('session.cookie') => $id]);
    $request->setUserResolver(fn(): ?User => $user);

    if ($refresh) {
        $request->headers->set(StartSession::REFRESH, '1');
    }

    return $request;
}

function refreshSessionInBetween(Request $request, Closure $meanwhile): void
{
    app(StartSession::class)->handle($request, function (Request $request) use ($meanwhile): Response {
        $meanwhile($request);

        return new Response('');
    });
}

test("a refresh from another tab leaves a change's message for the page that made it", function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $id = refreshSessionSignedIn($user->fresh());

    $this->withCookie(config('session.cookie'), $id)
        ->put(route('settings.team-name.update'), ['name' => 'Renamed'])
        ->assertSessionHas('success', 'Saved.');

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'panel',
        StartSession::REFRESH => '1',
    ])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertJsonPath('component', 'Dashboard')
        ->assertJsonMissingPath('flash');

    $this->flushHeaders()
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->hasFlash('success', 'Saved.'));
});

test('a refresh that lands on a message, like a finished destroy, hands over to a full page that shows it', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner Cloud', 'api_token' => 'tok-1']);
    $server = Server::factory()->fromProvider($account)->create(['name' => 'outpost-02', 'status' => ServerStatus::Active]);
    CloudTask::start($server, CloudTask::DESTROY, 'delete');
    CloudTask::finish($server);
    $server->delete();
    $id = refreshSessionSignedIn($user->fresh());

    $this->withCookie(config('session.cookie'), $id)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
            'X-Inertia-Partial-Component' => 'servers/Show',
            'X-Inertia-Partial-Data' => 'server',
            StartSession::REFRESH => '1',
        ])
        ->get(route('servers.show', $server))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('dashboard'));

    $this->flushHeaders()
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->hasFlash('success', 'Destroyed. outpost-02 was deleted at Hetzner Cloud, and billing for it stopped.'));
});

test('a refresh never writes back the copy of the session it read', function () {
    $user = User::factory()->create();
    $id = refreshSessionSignedIn($user);

    refreshSessionInBetween(refreshSessionRequest($id, user: $user), function () use ($id): void {
        $other = refreshSessionStored($id);
        $other->put('saved', 'by another tab');
        $other->save();
    });

    expect(refreshSessionStored($id)->get('saved'))->toBe('by another tab');
});

test('a session is saved as usual unless a signed-in page asks to refresh', function (string $method, bool $refresh, bool $signedIn) {
    $user = User::factory()->create();
    $id = refreshSessionSignedIn($signedIn ? $user : null);

    refreshSessionInBetween(
        refreshSessionRequest($id, $method, $refresh, $signedIn ? $user : null),
        fn(Request $request) => $request->session()->put('url.intended', '/servers'),
    );

    expect(refreshSessionStored($id)->get('url.intended'))->toBe('/servers');
})->with([
    'a visit' => ['GET', false, true],
    'a form' => ['POST', true, true],
    'a refresh while signed out' => ['GET', true, false],
]);

test('a refresh that leaves a message saves the session, so the message is kept', function () {
    $user = User::factory()->create();
    $id = refreshSessionSignedIn($user);

    refreshSessionInBetween(refreshSessionRequest($id, user: $user), fn(Request $request) => $request->session()->flash('success', 'Destroyed.'));

    expect(refreshSessionStored($id)->get('success'))->toBe('Destroyed.');
});

test('a refresh that renews the session saves the new one', function () {
    $user = User::factory()->create();
    $id = refreshSessionSignedIn($user);

    refreshSessionInBetween(refreshSessionRequest($id, user: $user), fn(Request $request) => $request->session()->regenerate(true));

    $renewed = app('session')->driver()->getId();

    expect($renewed)->not->toBe($id)
        ->and(refreshSessionStored($renewed)->get(Auth::guard('web')->getName()))->toBe($user->getKey());
});

test('a refresh keeps the session alive without writing it', function (string $kind) {
    $inner = match ($kind) {
        'database' => new DatabaseSessionHandler(DB::connection(), config('session.table'), 120, app()),
        'cache' => new CacheBasedSessionHandler(Cache::store('array'), 120),
        'memory' => new ArraySessionHandler(120),
    };
    $sessions = new EndedSessions($inner, Cache::store('array'), 120);
    $sessions->write('kept', 'payload');
    $sessions->write('idle', 'payload');

    $this->travel(100)->minutes();
    $sessions->keepAlive('kept');
    $this->travel(100)->minutes();

    expect($sessions->read('kept'))->toBe('payload')
        ->and($sessions->read('idle'))->toBe('');
})->with(['database', 'cache', 'memory']);

test('keeping a signed-out session alive never brings it back', function () {
    $sessions = app('session')->driver()->getHandler();
    $id = refreshSessionSignedIn(User::factory()->create());
    $sessions->destroy($id);

    $sessions->keepAlive($id);

    expect($sessions->read($id))->toBe('');
});
