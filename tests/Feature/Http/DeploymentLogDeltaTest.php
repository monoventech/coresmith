<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function deployLogDeltaMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function deployLogDeltaDeployment(Team $team, string $log): Deployment
{
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    return Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running, 'log' => $log, 'started_at' => now()]);
}

function deployLogDeltaFetch(User $user, Deployment $deployment, mixed $from, ?Application $through = null): TestResponse
{
    $application = $through ?? $deployment->application;

    return test()->actingAs($user)->getJson(route('servers.applications.deployments.log', [$application->server, $application, $deployment, 'from' => $from]));
}

test('the log comes back from the byte the page asks for, with its length and the deployment', function () {
    $team = Team::factory()->create();
    $deployment = deployLogDeltaDeployment($team, "\$ npm ci\nadded 1204 packages in 41s");

    deployLogDeltaFetch(deployLogDeltaMember($team), $deployment, 8)
        ->assertOk()
        ->assertJson([
            'from' => 8,
            'text' => "\nadded 1204 packages in 41s",
            'bytes' => strlen("\$ npm ci\nadded 1204 packages in 41s"),
            'deployment' => ['id' => $deployment->id, 'status' => 'running', 'log_bytes' => strlen("\$ npm ci\nadded 1204 packages in 41s")],
        ])
        ->assertJsonMissingPath('deployment.log');
});

test('a starting point before the log or past its end is held to the log', function (mixed $from, int $expected) {
    $team = Team::factory()->create();
    $deployment = deployLogDeltaDeployment($team, '$ npm ci');

    deployLogDeltaFetch(deployLogDeltaMember($team), $deployment, $from)
        ->assertOk()
        ->assertJson(['from' => $expected, 'bytes' => 8]);
})->with([
    'negative' => [-5, 0],
    'past the end' => [999, 8],
    'not a number' => ['abc', 0],
]);

test('a long log comes back in pieces whose starts and lengths chain into the whole log', function () {
    $team = Team::factory()->create();
    $user = deployLogDeltaMember($team);
    $log = implode("\n", array_fill(0, 6000, 'dist/assets/chunk.js   12.34 kB │ gzip: 4.56 kB'));
    $deployment = deployLogDeltaDeployment($team, $log);
    $text = '';
    $from = 0;
    $reads = 0;

    do {
        $json = deployLogDeltaFetch($user, $deployment, $from)->assertOk()->json();

        expect($json['from'])->toBe($from)
            ->and(strlen($json['text']))->toBeLessThanOrEqual(262_144)
            ->and($json['bytes'])->toBe(strlen($log));

        $text .= $json['text'];
        $from += strlen($json['text']);
        $reads++;
    } while ($from < $json['bytes']);

    expect($reads)->toBeGreaterThan(1)
        ->and($text)->toBe($log);
});

test('a starting point inside a character moves back to where the character starts', function () {
    $team = Team::factory()->create();
    $deployment = deployLogDeltaDeployment($team, 'ok ✓ done');

    deployLogDeltaFetch(deployLogDeltaMember($team), $deployment, 4)
        ->assertOk()
        ->assertJson(['from' => 3, 'text' => '✓ done']);
});

test('someone outside the team gets a not found', function () {
    $deployment = deployLogDeltaDeployment(Team::factory()->create(), '$ npm ci');

    deployLogDeltaFetch(deployLogDeltaMember(Team::factory()->create(), TeamRole::Owner), $deployment, 0)->assertNotFound();
});

test('a deployment reached through another app is a not found', function () {
    $team = Team::factory()->create();
    $deployment = deployLogDeltaDeployment($team, '$ npm ci');
    $other = Application::factory()->for($team)->for($deployment->application->server)->create();

    deployLogDeltaFetch(deployLogDeltaMember($team), $deployment, 0, $other)->assertNotFound();
});

test('more than 120 reads a minute are turned away', function () {
    $team = Team::factory()->create();
    $user = deployLogDeltaMember($team);
    $deployment = deployLogDeltaDeployment($team, '$ npm ci');

    foreach (range(1, 120) as $read) {
        deployLogDeltaFetch($user, $deployment, 0)->assertOk();
    }

    deployLogDeltaFetch($user, $deployment, 0)->assertTooManyRequests();
});
