<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Services\FrontDoor\FrontDoorAddresses;

function frontDoorAddressesDoor(string $prefix, int $homes = 1): Server
{
    $door = Server::factory()->create();

    for ($home = 0; $home < $homes; $home++) {
        ServerFrontDoor::query()->create([
            'server_id' => Server::factory()->create(['team_id' => $door->team_id])->id,
            'door_server_id' => $door->id,
            'state' => ServerFrontDoor::OPEN,
            'subnet' => "{$prefix}.0/24",
            'door_address' => "{$prefix}.1",
            'home_address' => "{$prefix}." . ($home + 2),
        ]);
    }

    return $door;
}

test('the first home on a door gets 10.205.k.2 in the lowest /24 no other door uses', function () {
    frontDoorAddressesDoor('10.205.0');
    frontDoorAddressesDoor('10.205.1');

    expect(app(FrontDoorAddresses::class)->allocate(Server::factory()->create()))
        ->toBe(['subnet' => '10.205.2.0/24', 'door' => '10.205.2.1', 'home' => '10.205.2.2']);
});

test('a second home on the same door shares its /24 and takes the next address', function () {
    $door = frontDoorAddressesDoor('10.205.4', homes: 2);

    expect(app(FrontDoorAddresses::class)->allocate($door))
        ->toBe(['subnet' => '10.205.4.0/24', 'door' => '10.205.4.1', 'home' => '10.205.4.4']);
});

test('a door carries only the hosts it holds while open', function () {
    $frontDoor = new ServerFrontDoor(['state' => ServerFrontDoor::OPEN, 'hosts' => ['a.example']]);

    expect($frontDoor->carries('a.example'))->toBeTrue()
        ->and($frontDoor->carries('A.Example'))->toBeTrue()
        ->and($frontDoor->carries('b.example'))->toBeFalse();

    $frontDoor->state = ServerFrontDoor::OPENING;

    expect($frontDoor->carries('a.example'))->toBeFalse();
});

test("this machine's front door is the open one whose home runs Coresmith", function () {
    $door = Server::factory()->create();
    $machine = Server::factory()->create(['team_id' => $door->team_id, 'runs_coresmith' => true]);
    $other = Server::factory()->create(['team_id' => $door->team_id]);
    $attributes = ['door_server_id' => $door->id, 'subnet' => '10.205.0.0/24', 'door_address' => '10.205.0.1'];

    ServerFrontDoor::query()->create([...$attributes, 'server_id' => $other->id, 'state' => ServerFrontDoor::OPEN, 'home_address' => '10.205.0.2']);
    $mine = ServerFrontDoor::query()->create([...$attributes, 'server_id' => $machine->id, 'state' => ServerFrontDoor::OPENING, 'home_address' => '10.205.0.3']);

    expect(ServerFrontDoor::forThisMachine())->toBeNull();

    $mine->forceFill(['state' => ServerFrontDoor::OPEN])->save();

    expect(ServerFrontDoor::forThisMachine()?->is($mine))->toBeTrue()
        ->and($machine->frontDoor()->first()?->is($mine))->toBeTrue()
        ->and($door->frontDoorHomes()->count())->toBe(2);
});
