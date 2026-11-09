<?php

declare(strict_types=1);

use App\Services\Address\HttpCaddyAdmin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->caddyfile = tempnam(sys_get_temp_dir(), 'caddyfile');
    file_put_contents($this->caddyfile, "{\n    admin unix//run/caddy-admin/admin.sock|0222\n}\n");
    config(['coresmith.address.caddyfile' => $this->caddyfile]);
});

afterEach(function () {
    @unlink($this->caddyfile);
});

test('Coresmith loads its Caddyfile through the admin socket the installer set up, with no origin to forge', function () {
    Http::fake(['*' => Http::response()]);
    config(['coresmith.address.caddy_admin_socket' => '/run/caddy-admin/admin.sock']);

    (new HttpCaddyAdmin)->reload();

    Http::assertSent(fn(Request $request): bool => $request->url() === 'http://localhost/load'
        && $request->method() === 'POST'
        && !$request->hasHeader('Origin')
        && $request->header('Content-Type') === ['text/caddyfile']
        && $request->body() === file_get_contents($this->caddyfile));
});

test('an install from before the socket loads it on localhost with the origin Caddy expects', function () {
    Http::fake(['*' => Http::response()]);
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
