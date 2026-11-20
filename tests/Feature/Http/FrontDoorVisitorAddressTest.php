<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerFrontDoor;
use Illuminate\Support\Facades\Route;

beforeEach(fn() => Route::get('front-door-visitor-probe', fn() => response(request()->ip())));

function frontDoorVisitorDoor(string $state = ServerFrontDoor::OPEN, bool $runsCoresmith = true): ServerFrontDoor
{
    $home = Server::factory()->create(['runs_coresmith' => $runsCoresmith]);
    $door = Server::factory()->create(['team_id' => $home->team_id]);

    return ServerFrontDoor::query()->create([
        'server_id' => $home->id,
        'door_server_id' => $door->id,
        'state' => $state,
        'subnet' => '10.205.0.0/24',
        'door_address' => '10.205.0.1',
        'home_address' => '10.205.0.2',
    ]);
}

test("through this machine's front door, Coresmith sees the visitor's address", function (string $visitor) {
    frontDoorVisitorDoor();

    $this->withServerVariables(['REMOTE_ADDR' => '10.205.0.1'])
        ->withHeader('X-Forwarded-For', "{$visitor}, 10.205.0.1")
        ->get('/front-door-visitor-probe')
        ->assertOk()
        ->assertContent($visitor);
})->with(['198.51.100.23', '2001:db8::23']);

test('a request from another address keeps its own address, whatever it claims', function () {
    frontDoorVisitorDoor();

    $this->withServerVariables(['REMOTE_ADDR' => '10.205.0.9'])
        ->withHeader('X-Forwarded-For', '198.51.100.23')
        ->get('/front-door-visitor-probe')
        ->assertContent('10.205.0.9');
});

test("while the front door isn't open, its address isn't trusted", function (string $state) {
    frontDoorVisitorDoor($state);

    $this->withServerVariables(['REMOTE_ADDR' => '10.205.0.1'])
        ->withHeader('X-Forwarded-For', '198.51.100.23, 10.205.0.1')
        ->get('/front-door-visitor-probe')
        ->assertContent('10.205.0.1');
})->with([ServerFrontDoor::OPENING, ServerFrontDoor::FAILED, ServerFrontDoor::CLOSING]);

test("another server's front door doesn't make its address trusted here", function () {
    frontDoorVisitorDoor(runsCoresmith: false);

    $this->withServerVariables(['REMOTE_ADDR' => '10.205.0.1'])
        ->withHeader('X-Forwarded-For', '198.51.100.23, 10.205.0.1')
        ->get('/front-door-visitor-probe')
        ->assertContent('10.205.0.1');
});

test("a header that isn't an address leaves the front door's address in place", function () {
    frontDoorVisitorDoor();

    $this->withServerVariables(['REMOTE_ADDR' => '10.205.0.1'])
        ->withHeader('X-Forwarded-For', 'nonsense, 10.205.0.1')
        ->get('/front-door-visitor-probe')
        ->assertContent('10.205.0.1');
});

test("with Cloudflare's proxy in front of the front door, Coresmith still sees the visitor's address", function () {
    frontDoorVisitorDoor();

    $this->withServerVariables(['REMOTE_ADDR' => '10.205.0.1'])
        ->withHeader('X-Forwarded-For', '172.70.1.9, 10.205.0.1')
        ->withHeader('CF-Connecting-IP', '198.51.100.23')
        ->get('/front-door-visitor-probe')
        ->assertContent('198.51.100.23');
});
