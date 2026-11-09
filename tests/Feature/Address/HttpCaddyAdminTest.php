<?php

declare(strict_types=1);

use App\Services\Address\HttpCaddyAdmin;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Caddy\CoresmithSite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->caddyfile = tempnam(sys_get_temp_dir(), 'caddyfile');
    file_put_contents($this->caddyfile, "{\n    admin unix//run/caddy-admin/admin.sock|0222\n}\n");
    config(['coresmith.address.caddyfile' => $this->caddyfile]);
});

afterEach(function () {
    @unlink($this->caddyfile);
});

test('Coresmith loads its Caddyfile through the admin socket the installer set up, with no origin to forge', function () {
    Http::fake(['localhost/config/apps/http/servers/coresmith' => Http::response('null'), '*' => Http::response()]);
    config(['coresmith.address.caddy_admin_socket' => '/run/caddy-admin/admin.sock']);

    (new HttpCaddyAdmin)->reload();

    Http::assertSent(fn(Request $request): bool => $request->url() === 'http://localhost/load'
        && $request->method() === 'POST'
        && !$request->hasHeader('Origin')
        && $request->header('Content-Type') === ['text/caddyfile']
        && $request->body() === file_get_contents($this->caddyfile));
});

test('an install from before the socket loads it on localhost with the origin Caddy expects', function () {
    Http::fake(['localhost:2019/config/apps/http/servers/coresmith' => Http::response('null'), '*' => Http::response()]);
    config(['coresmith.address.caddy_admin_socket' => '']);

    (new HttpCaddyAdmin)->reload();

    Http::assertSent(fn(Request $request): bool => $request->url() === 'http://localhost:2019/load'
        && $request->header('Origin') === ['http://localhost:2019']);
});

test('a site Caddy refuses says what Caddy said', function () {
    Http::fake(['*' => Http::response(['error' => 'adapting config using caddyfile: unrecognized directive'], 400)]);
    config(['coresmith.address.caddy_admin_socket' => '/run/caddy-admin/admin.sock']);

    expect(fn() => (new HttpCaddyAdmin)->reload())
        ->toThrow(RuntimeException::class, 'Caddy refused the new site: adapting config using caddyfile: unrecognized directive');
});

function caddyAdminComposedCurrent(): string
{
    $site = CoresmithSite::fromAdapted((string) file_get_contents(base_path('tests/Fixtures/Caddy/adapted-internal.json')));
    $current = json_decode((new CaddyInstaller)->thisMachineConfig($site, null), false);
    array_splice($current->apps->http->servers->coresmith->routes, 1, 0, [json_decode('{"@id":"app-shop","match":[{"host":["shop.example.com"]}],"handle":[{"handler":"encode","encodings":{"gzip":{}}}],"terminal":true}')]);

    return (string) json_encode($current, JSON_UNESCAPED_SLASHES);
}

function caddyAdminComposedFake(array $overrides = []): void
{
    config(['coresmith.address.caddy_admin_socket' => '/run/caddy-admin/admin.sock']);
    Http::fake($overrides + [
        'localhost/config/apps/http/servers/coresmith' => Http::response('{"listen":[":443"]}'),
        'localhost/config/' => Http::response(caddyAdminComposedCurrent()),
        'localhost/adapt' => Http::response('{"result":' . file_get_contents(base_path('tests/Fixtures/Caddy/adapted-public.json')) . ',"warnings":[]}'),
        'localhost/load' => Http::response(),
    ]);
}

function caddyAdminLoaded(): ?Request
{
    return collect(Http::recorded())->map(fn(array $pair): Request => $pair[0])->first(fn(Request $request): bool => $request->url() === 'http://localhost/load');
}

test("on Coresmith's own machine an address change swaps only Coresmith's routes, and the apps stay", function () {
    caddyAdminComposedFake();

    (new HttpCaddyAdmin)->reload();

    $load = caddyAdminLoaded();
    $config = json_decode($load->body(), true);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'http://localhost/adapt'
        && $request->method() === 'POST'
        && $request->header('Content-Type') === ['text/caddyfile']
        && $request->body() === file_get_contents($this->caddyfile));

    expect($load->header('Content-Type'))->toBe(['application/json'])
        ->and(array_column($config['apps']['http']['servers']['coresmith']['routes'], '@id'))->toBe(['coresmith-site', 'app-shop', 'coresmith-any'])
        ->and($config['apps']['http']['servers']['coresmith']['routes'][0]['match'])->toBe([['host' => ['coresmith.example.com', 'old.example.net']]])
        ->and(substr_count($load->body(), '"gzip": {}'))->toBe(3);
});

test('Coresmith knows Caddy runs the managed config by asking for the server every managed config has', function (string $answer, bool $managed) {
    config(['coresmith.address.caddy_admin_socket' => '/run/caddy-admin/admin.sock']);
    Http::fake(['localhost/config/apps/http/servers/coresmith' => Http::response($answer)]);

    expect((new HttpCaddyAdmin)->managed())->toBe($managed);
})->with(['the managed config' => ['{"listen":[":443"]}', true], "Coresmith's own Caddyfile, where Caddy answers null" => ['null', false]]);

test("a Caddy that isn't answering isn't taken for one holding Coresmith inside the managed config", function () {
    config(['coresmith.address.caddy_admin_socket' => '/run/caddy-admin/admin.sock']);
    Http::fake(fn() => throw new ConnectionException('connection refused'));

    expect((new HttpCaddyAdmin)->managed())->toBeFalse();
});

test("a new site Caddy refuses on Coresmith's own machine leaves the running config alone and says why", function () {
    caddyAdminComposedFake(['localhost/adapt' => Http::response(['error' => 'adapting config using caddyfile: unrecognized directive: foo'], 400)]);

    expect(fn() => (new HttpCaddyAdmin)->reload())->toThrow(RuntimeException::class, 'Caddy refused the new site: adapting config using caddyfile: unrecognized directive: foo')
        ->and(caddyAdminLoaded())->toBeNull();
});

test("an address change waits for a deploy changing Caddy on Coresmith's machine, and says so if it can't", function () {
    Sleep::fake(syncWithCarbon: true);
    Cache::lock(CaddyApiClient::THIS_MACHINE_LOCK, 600)->get();
    caddyAdminComposedFake();

    expect(fn() => (new HttpCaddyAdmin)->reload())->toThrow(RuntimeException::class, 'Caddy was busy with another change on this machine. Try again in a minute.')
        ->and(caddyAdminLoaded())->toBeNull();
});
