<?php

declare(strict_types=1);

use App\Services\Updates\Release;
use App\Services\Updates\ReleaseHealth;
use App\Services\Updates\UpdateFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->paths = updatesUseTemporaryPaths();
    updatesInstall($this->paths['root'], '0.1.0', '8413dcea20f7fd49b704790b8013b5dd4340fa16');
    $this->release = updatesRelease($this->paths['root'], '0.1.1', 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0');
    config(['app.url' => 'https://panel.example.com']);
});

afterEach(function () {
    updatesCleanUp();
});

function updatesHealthFailure(Release $release, int $timeout = 60): UpdateFailed
{
    try {
        app(ReleaseHealth::class)->check($release, $timeout);
    } catch (UpdateFailed $e) {
        return $e;
    }

    throw new RuntimeException('Expected the health check to fail.');
}

test('a release that is healthy and serving passes', function () {
    updatesMachine();
    $answers = [
        fn() => Http::failedConnection(),
        fn() => Http::response('Bad gateway', 502),
        fn() => Http::response(['release' => '0.1.0-8413dce', 'version' => '0.1.0']),
        fn() => Http::response(['release' => $this->release->name, 'version' => '0.1.1']),
    ];
    $calls = 0;
    updatesAnswer(updatesProbeUrl(), function () use ($answers, &$calls) {
        return $answers[min($calls++, 3)]();
    });

    app(ReleaseHealth::class)->check($this->release, 60);

    Http::assertSentCount(4);
    Http::assertSent(fn(Request $request): bool => $request->url() === 'http://127.0.0.1:8484/api/up/release');
    Http::assertNotSent(fn(Request $request): bool => $request->url() !== 'http://127.0.0.1:8484/api/up/release');
    Sleep::assertSleptTimes(3);

    expect(collect(updatesProcesses())->firstWhere('command', PHP_BINARY . ' artisan coresmith:health --json'))
        ->path->toBe($this->release->path)
        ->timeout->toBe(120);
});

test('the probe asks for json', function () {
    updatesMachine();
    updatesProbe($this->release->name);

    app(ReleaseHealth::class)->check($this->release, 60);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'http://127.0.0.1:8484/api/up/release' && $request->hasHeader('Accept', 'application/json'));
});

test('a failing health check names the checks that failed', function () {
    updatesMachine(['artisan:coresmith:health' => [1, '', json_encode(['ok' => false, 'checks' => [
        ['name' => 'migrations', 'status' => 'fail', 'detail' => '2 migrations have not run.'],
        ['name' => 'horizon', 'status' => 'warn', 'detail' => "Horizon isn't running."],
        ['name' => 'assets', 'status' => 'fail', 'detail' => "The interface hasn't been built."],
    ]])]]);

    $failed = updatesHealthFailure($this->release);

    expect($failed->step)->toBe('health')
        ->and($failed->getMessage())->toBe("The new release failed its health check: migrations: 2 migrations have not run. assets: The interface hasn't been built.");

    Http::assertNothingSent();
});

test('a health check that exits cleanly but reports a failure still fails', function () {
    updatesMachine(['artisan:coresmith:health' => [0, '', json_encode(['ok' => false, 'checks' => [['name' => 'keys', 'status' => 'fail', 'detail' => 'APP_KEY or CORESMITH_VAULT_KEY is missing.']]])]]);

    expect(updatesHealthFailure($this->release)->getMessage())->toBe('The new release failed its health check: keys: APP_KEY or CORESMITH_VAULT_KEY is missing.');
});

test('a health check that says nothing readable fails with what it printed', function () {
    updatesMachine(['artisan:coresmith:health' => [255, "PHP Fatal error:  Uncaught Error: Class \"Foo\" not found\n"]]);

    expect(updatesHealthFailure($this->release)->getMessage())->toBe('The new release failed its health check: PHP Fatal error:  Uncaught Error: Class "Foo" not found');

    Http::assertNothingSent();
});

test('a panel still serving the old release fails the check', function () {
    updatesMachine();
    updatesProbe('0.1.0-8413dce');

    $failed = updatesHealthFailure($this->release, 6);

    expect($failed->step)->toBe('health')
        ->and($failed->getMessage())->toBe('The panel is still serving 0.1.0-8413dce, not the new release.');

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

test('a panel that never answers fails the check', function (Closure $answer, string $message) {
    updatesMachine();
    updatesAnswer(updatesProbeUrl(), $answer);

    expect(updatesHealthFailure($this->release, 4)->getMessage())->toBe($message);
})->with([
    'no connection' => [fn() => Http::failedConnection(), "The panel didn't answer on http://127.0.0.1:8484/api/up/release."],
    'a constant 503' => [fn() => Http::response('Service Unavailable', 503), 'The panel answered 503 on http://127.0.0.1:8484/api/up/release.'],
    'an answer that names no release' => [fn() => Http::response('<html>ok</html>'), 'The panel answered on http://127.0.0.1:8484/api/up/release without saying which release it serves.'],
]);

test('the probe goes to the loopback site whatever address the panel has', function (string $url) {
    config(['app.url' => $url]);
    updatesMachine();
    updatesProbe($this->release->name);

    app(ReleaseHealth::class)->check($this->release, 60);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'http://127.0.0.1:8484/api/up/release');
    Http::assertSentCount(1);
})->with([
    'a domain' => 'https://panel.example.com',
    'an ip address' => 'https://203.0.113.9',
    'its own port' => 'http://panel.lan:8080',
]);

test('a configured probe address is used', function () {
    config(['coresmith.updates.probe_url' => 'http://127.0.0.1:9484/api/up/release']);
    updatesMachine();
    updatesProbe($this->release->name);

    app(ReleaseHealth::class)->check($this->release, 60);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'http://127.0.0.1:9484/api/up/release');
});
