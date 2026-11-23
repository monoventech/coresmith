<?php

declare(strict_types=1);

use App\Enums\Edition;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Moves\MoveSide;
use App\Services\Panel\ServerPresenter;
use Tests\Support\CloudBilling;

function moveSideOther(Server $server): string
{
    $fresh = $server->fresh();

    return (string) app(ServerPresenter::class)->header($fresh, User::query()->findOrFail($fresh->team->owner_id))['move']['other'];
}

test('each edition has a name for the other side of a move', function () {
    expect(MoveSide::label(Edition::Cloud))->toBe('Coresmith Cloud')
        ->and(MoveSide::label(Edition::SelfHosted))->toBe('self-hosted Coresmith');
});

test('refusals name where a code or a file comes from, as the reader sees it', function () {
    expect(MoveSide::destination())->toBe('the Coresmith the team is moving to')
        ->and(MoveSide::origin())->toBe('the Coresmith the team is leaving');

    CloudBilling::enable();

    expect(MoveSide::destination())->toBe('your self-hosted Coresmith')
        ->and(MoveSide::origin())->toBe('your self-hosted Coresmith');
});

test('a team on its way out names where it is going, and a move from before moves said so goes to self-hosted Coresmith', function () {
    $team = Team::factory()->owned()->create(['moving_at' => now(), 'moving_to' => 'https://app.coresmith.io', 'moving_edition' => 'cloud']);
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Moving]);
    $older = Team::factory()->owned()->create(['moving_at' => now(), 'moving_to' => 'https://panel.example.com']);
    $old = Server::factory()->for($older)->create(['status' => ServerStatus::Moving]);

    expect(moveSideOther($server))->toBe('Coresmith Cloud')
        ->and(moveSideOther($old))->toBe('self-hosted Coresmith');
});

test('an arriving server names the Coresmith it came from, and one from before servers said so came from Coresmith Cloud', function () {
    $team = Team::factory()->owned()->create();
    $fromSelfHosted = Server::factory()->for($team)->create(['status' => ServerStatus::Moving, 'takeover' => ['state' => 'waiting', 'from' => 'self-hosted Coresmith']]);
    $older = Server::factory()->for($team)->create(['status' => ServerStatus::Moving, 'takeover' => ['state' => 'waiting']]);

    expect(moveSideOther($fromSelfHosted))->toBe('self-hosted Coresmith')
        ->and(moveSideOther($older))->toBe('Coresmith Cloud');
});
