<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerTunnel;
use Illuminate\Support\Facades\Route;

beforeEach(fn() => Route::get('cloudflare-visitor-probe', fn() => response(request()->ip())));

test("behind Cloudflare's proxy, Coresmith sees the visitor's address", function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.70.1.9'])
        ->withHeader('CF-Connecting-IP', '198.51.100.23')
        ->get('/cloudflare-visitor-probe')
        ->assertOk()
        ->assertContent('198.51.100.23');
});

test("a CF-Connecting-IP header is ignored when the connection doesn't come from Cloudflare", function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->withHeader('CF-Connecting-IP', '198.51.100.23')
        ->get('/cloudflare-visitor-probe')
        ->assertContent('203.0.113.50');
});

test("a header that isn't an address leaves Cloudflare's address in place", function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.70.1.9'])
        ->withHeader('CF-Connecting-IP', 'nonsense')
        ->get('/cloudflare-visitor-probe')
        ->assertContent('172.70.1.9');
});

test("through this machine's Cloudflare tunnel, Coresmith sees the visitor's address", function (string $loopback) {
    $server = Server::factory()->create(['runs_coresmith' => true]);
    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN]);

    $this->withServerVariables(['REMOTE_ADDR' => $loopback])
        ->withHeader('CF-Connecting-IP', '198.51.100.23')
        ->get('/cloudflare-visitor-probe')
        ->assertContent('198.51.100.23');
})->with(['127.0.0.1', '::1']);

test('without a tunnel on this machine, a local request keeps its own address', function (string $state) {
    $server = Server::factory()->create(['runs_coresmith' => true]);
    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => $state]);

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('CF-Connecting-IP', '198.51.100.23')
        ->get('/cloudflare-visitor-probe')
        ->assertContent('127.0.0.1');
})->with([ServerTunnel::OPENING, ServerTunnel::FAILED]);

test("another server's tunnel doesn't make local requests trusted here", function () {
    $server = Server::factory()->create(['runs_coresmith' => false]);
    ServerTunnel::query()->create(['server_id' => $server->id, 'state' => ServerTunnel::OPEN]);

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('CF-Connecting-IP', '198.51.100.23')
        ->get('/cloudflare-visitor-probe')
        ->assertContent('127.0.0.1');
});
