<?php

declare(strict_types=1);

use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdatePaths;
use App\Services\Updates\Version;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    updatesUseTemporaryPaths();
    config(['coresmith.updates.enabled' => true, 'coresmith.version' => '1.0.1', 'coresmith.updates.check_url' => 'https://get.coresmith.io/v1/version']);
});

afterEach(function () {
    updatesCleanUp();
});

function updatesScheduledCheck(): Event
{
    return collect(app(Schedule::class)->events())->first(fn(Event $event): bool => $event->description === 'coresmith:check-update');
}

test('a check records the feed and offers the newer release', function () {
    updatesFeed(['latest' => '1.0.2', 'changelog_url' => 'https://github.com/monoven/coresmith/releases/tag/v1.0.2']);

    $check = app(UpdateChecker::class)->check();

    expect($check->ok())->toBeTrue()
        ->and((string) $check->available(Version::current()))->toBe('1.0.2')
        ->and(app(UpdateChecker::class)->latest()->toArray())->toBe($check->toArray())
        ->and(fileperms(app(UpdatePaths::class)->checkFile()) & 0777)->toBe(0600)
        ->and(fileperms(app(UpdatePaths::class)->state()) & 0777)->toBe(0700);

    Http::assertSent(fn(Request $request) => $request->url() === 'https://get.coresmith.io/v1/version'
        && $request->header('User-Agent') === ['Coresmith']);
});

test('the check sends nothing about this install', function () {
    updatesFeed(['latest' => '1.0.2']);

    app(UpdateChecker::class)->check();

    Http::assertSent(fn(Request $request) => parse_url($request->url(), PHP_URL_QUERY) === null
        && !str_contains(implode(' ', $request->header('User-Agent')), '1.0.1'));
});

test('a withdrawn latest release is never offered', function () {
    updatesFeed(['latest' => '1.0.2', 'withdrawn' => ['1.0.2']]);

    expect(app(UpdateChecker::class)->check()->available(Version::current()))->toBeNull();
});

test('a withdrawn running release is flagged', function () {
    updatesFeed(['latest' => '1.0.2', 'withdrawn' => ['1.0.1']]);

    $check = app(UpdateChecker::class)->check();

    expect($check->currentWithdrawn(Version::current()))->toBeTrue()
        ->and($check->currentWithdrawn(Version::parse('1.0.2')))->toBeFalse();
});

test('a running release below the minimum is unsupported', function () {
    updatesFeed(['latest' => '1.0.3', 'minimum' => '1.0.2']);

    $check = app(UpdateChecker::class)->check();

    expect($check->unsupported(Version::current()))->toBeTrue()
        ->and($check->unsupported(Version::parse('1.0.2')))->toBeFalse();
});

test('nothing is offered when the panel is already current or ahead', function (string $latest) {
    updatesFeed(['latest' => $latest]);

    expect(app(UpdateChecker::class)->check()->available(Version::current()))->toBeNull();
})->with(['1.0.1', '1.0.0']);

test('an automatic update is only a patch on the same line', function () {
    updatesFeed(['latest' => '1.0.4']);
    $patch = app(UpdateChecker::class)->check();

    updatesFeed(['latest' => '1.1.0']);
    $minor = app(UpdateChecker::class)->check();

    updatesFeed(['latest' => '1.0.4', 'withdrawn' => ['1.0.4']]);
    $withdrawn = app(UpdateChecker::class)->check();

    expect((string) $patch->autoTarget(Version::current()))->toBe('1.0.4')
        ->and((string) $minor->available(Version::current()))->toBe('1.1.0')
        ->and($minor->autoTarget(Version::current()))->toBeNull()
        ->and($withdrawn->available(Version::current()))->toBeNull()
        ->and($withdrawn->autoTarget(Version::current()))->toBeNull();
});

test('the channel is the feed\'s, and stable until a feed says otherwise', function () {
    expect(app(UpdateChecker::class)->latest())->toBeNull();

    updatesFeed(['latest' => '1.1.0-beta.1', 'channel' => 'beta']);

    expect(app(UpdateChecker::class)->check()->channel())->toBe('beta');
});

test('a failed check keeps the last release it knew about', function () {
    updatesFeed(['latest' => '1.0.2']);
    app(UpdateChecker::class)->check();

    updatesFeed('<html>Bad gateway</html>');
    $check = app(UpdateChecker::class)->check();

    expect((string) $check->feed->latest)->toBe('1.0.2')
        ->and($check->error)->toBe("The release feed at get.coresmith.io sent something Coresmith doesn't understand: the answer isn't JSON.")
        ->and($check->ok())->toBeFalse()
        ->and($check->failedAt)->not->toBeNull()
        ->and((string) $check->available(Version::current()))->toBe('1.0.2')
        ->and(app(UpdateChecker::class)->latest()->toArray())->toBe($check->toArray());
});

test('a check that succeeds after a failure clears the error', function () {
    updatesFeed('nope', 500);
    app(UpdateChecker::class)->check();

    updatesFeed(['latest' => '1.0.3']);
    $check = app(UpdateChecker::class)->check();

    expect($check->ok())->toBeTrue()
        ->and($check->error)->toBeNull()
        ->and($check->failedAt)->toBeNull()
        ->and((string) $check->feed->latest)->toBe('1.0.3');
});

test('a broken feed is reported in plain words', function (Closure $answer, string $error) {
    $answer();

    $check = app(UpdateChecker::class)->check();

    expect($check->error)->toBe($error)
        ->and($check->ok())->toBeFalse()
        ->and($check->feed)->toBeNull();
})->with([
    'no connection' => [fn() => updatesFeed(fn() => Http::failedConnection()), "Coresmith couldn't reach the release feed at get.coresmith.io. Check this server can make outbound HTTPS requests, then try again."],
    'a server error' => [fn() => updatesFeed('Bad gateway', 502), 'The release feed at get.coresmith.io answered 502. Try again later.'],
    'a success that is not 200' => [fn() => updatesFeed('', 204), 'The release feed at get.coresmith.io answered 204. Try again later.'],
    'a body over 64 KB' => [fn() => updatesFeed(str_repeat('x', 70000)), "The release feed at get.coresmith.io sent something Coresmith doesn't understand: the answer is larger than 64 KB."],
    'a list instead of an object' => [fn() => updatesFeed('["1.0.2"]'), "The release feed at get.coresmith.io sent something Coresmith doesn't understand: the answer isn't a JSON object."],
    'a broken contract' => [fn() => updatesFeed(['latest' => 'soon']), "The release feed at get.coresmith.io sent something Coresmith doesn't understand: latest is not a version like 1.2.3."],
    'a hostile withdrawn list' => [fn() => updatesFeed(['latest' => '1.0.2', 'withdrawn' => ['1.0.1', '<script>']]), "The release feed at get.coresmith.io sent something Coresmith doesn't understand: withdrawn holds something that is not a version like 1.2.3."],
]);

test('a body of exactly 64 KB is read', function () {
    $json = json_encode(['latest' => '1.0.2', 'summary' => '']);
    updatesFeed(substr($json, 0, -1) . str_repeat(' ', 65536 - strlen($json)) . '}');

    expect(app(UpdateChecker::class)->check()->ok())->toBeTrue();
});

test('a feed far over 64 KB is cut off without downloading it', function () {
    $server = new Process([PHP_BINARY, '-r', <<<'PHP'
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGPIPE, SIG_IGN);
        }
        $server = stream_socket_server('tcp://127.0.0.1:0') ?: exit(1);
        echo 'port ', parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
        $client = stream_socket_accept($server, 10) ?: exit(1);
        while (($line = fgets($client)) !== false && trim($line) !== '') {
        }
        $total = 268435456;
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$total}\r\nConnection: close\r\n\r\n");
        $sent = 0;
        $chunk = str_repeat(' ', 65536);
        while ($sent < $total && ($written = @fwrite($client, $chunk)) > 0) {
            $sent += $written;
            echo "sent {$sent}\n";
        }
        PHP]);
    $server->setTimeout(60);
    $server->start();

    try {
        $server->waitUntil(fn(string $type, string $output): bool => str_contains($server->getOutput(), "\n"));
        preg_match('/^port (\d+)$/m', $server->getOutput(), $port);
        Http::preventStrayRequests(false);
        config(['coresmith.updates.check_url' => "http://127.0.0.1:{$port[1]}/v1/version"]);

        $check = app(UpdateChecker::class)->check();
        $server->wait();
        preg_match_all('/^sent (\d+)$/m', $server->getOutput(), $sent);

        expect($check->error)->toBe("The release feed at 127.0.0.1 sent something Coresmith doesn't understand: the answer is larger than 64 KB.")
            ->and((int) end($sent[1]))->toBeLessThan(16 * 1024 * 1024);
    } finally {
        $server->stop(0);
    }
});

test('a feed that redirects from https to http is not followed', function () {
    Http::fake([
        'https://get.coresmith.io/*' => Http::response('', 302, ['Location' => 'http://get.coresmith.io/v1/version']),
        'http://get.coresmith.io/*' => Http::response(['latest' => '1.0.2']),
    ]);

    $check = app(UpdateChecker::class)->check();

    expect($check->ok())->toBeFalse()
        ->and($check->error)->toBe("The release feed at get.coresmith.io redirected to an address that isn't https, so Coresmith didn't follow it. Check the update check URL, then try again.");

    Http::assertNotSent(fn(Request $request) => str_starts_with($request->url(), 'http://'));
});

test('a feed that redirects to another https address is followed', function () {
    Http::fake([
        'https://get.coresmith.io/*' => Http::response('', 302, ['Location' => 'https://feeds.coresmith.io/v1/version']),
        'https://feeds.coresmith.io/*' => Http::response(['latest' => '1.0.2']),
    ]);

    expect((string) app(UpdateChecker::class)->check()->available(Version::current()))->toBe('1.0.2');
});

test('a check URL that is not http or https is refused before any request', function () {
    config(['coresmith.updates.check_url' => 'file:///etc/passwd']);

    expect(app(UpdateChecker::class)->check()->error)->toBe('The update check URL must start with https:// or http://.');

    Http::assertNothingSent();
});

test('credentials in the check URL never reach the state file', function () {
    config(['coresmith.updates.check_url' => 'https://feeds:s3cretpassword@get.coresmith.io/v1/version']);
    updatesFeed(['latest' => '1.0.2']);

    app(UpdateChecker::class)->check();

    expect((string) file_get_contents(app(UpdatePaths::class)->checkFile()))->not->toContain('s3cretpassword');
});

test('checks are off when the install says so', function () {
    config(['coresmith.updates.check_disabled' => true]);

    expect(app(UpdateChecker::class)->enabled())->toBeFalse();

    $this->artisan('coresmith:check-update')
        ->expectsOutput('Update checks are off on this install.')
        ->assertExitCode(0);

    expect(app(UpdateChecker::class)->check()->error)->toBe('Update checks are off on this install.')
        ->and(is_file(app(UpdatePaths::class)->checkFile()))->toBeFalse();

    Http::assertNothingSent();
});

test('checks are off on an install the installer did not make', function () {
    config(['coresmith.updates.enabled' => false]);

    expect(app(UpdateChecker::class)->enabled())->toBeFalse();
});

test('the command says what it found', function () {
    updatesFeed(['latest' => '1.0.2', 'changelog_url' => 'https://github.com/monoven/coresmith/releases/tag/v1.0.2']);

    $this->artisan('coresmith:check-update')
        ->expectsOutput("Coresmith v1.0.2 is available. You're running v1.0.1.")
        ->expectsOutput('Release notes: https://github.com/monoven/coresmith/releases/tag/v1.0.2')
        ->assertExitCode(0);

    updatesFeed(['latest' => '1.0.1']);

    $this->artisan('coresmith:check-update')
        ->expectsOutput("You're on the latest version, v1.0.1.")
        ->assertExitCode(0);

    updatesFeed('Bad gateway', 502);

    $this->artisan('coresmith:check-update')
        ->expectsOutput('The release feed at get.coresmith.io answered 502. Try again later.')
        ->assertExitCode(1);
});

test('the daily check runs at a time of its own between 03:00 and 03:59', function () {
    $time = UpdateChecker::dailyAt();
    $times = collect(range(1, 10))->map(function (int $i): string {
        config(['app.key' => "base64:other-key-{$i}"]);

        return UpdateChecker::dailyAt();
    });

    expect($time)->toMatch('/^03:[0-5]\d$/')
        ->and(UpdateChecker::dailyAt() === $times->last())->toBeTrue()
        ->and($times->unique()->count())->toBeGreaterThan(1);
});

test('the daily check is scheduled at that time and only when updates are on', function () {
    $event = updatesScheduledCheck();
    $minute = (int) substr(UpdateChecker::dailyAt(), 3);

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe("{$minute} 3 * * *")
        ->and($event->filtersPass(app()))->toBeTrue();

    config(['coresmith.updates.enabled' => false]);

    expect($event->filtersPass(app()))->toBeFalse();
});
