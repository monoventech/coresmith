<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\PackageCredential;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\Packages\PackageCredentials;

function packageCredentialsApp(Team $team, ?Project $project = null): Application
{
    $server = Server::factory()->for($team)->create();
    $environment = $project === null ? null : Environment::factory()->for($project)->create();

    return Application::factory()->for($server)->for($team)->create(['environment_id' => $environment?->id]);
}

test('an app outside projects gets only the team-wide entries', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create();
    $teamWide = PackageCredential::factory()->for($team)->create();
    PackageCredential::factory()->for($team)->for($project)->python()->create();
    PackageCredential::factory()->create();

    expect(collect(app(PackageCredentials::class)->forApplication(packageCredentialsApp($team)))->pluck('id')->all())->toBe([$teamWide->id]);
});

test("a project's entry replaces the team-wide one for the same host and adds its own", function () {
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create();
    $other = Project::factory()->for($team)->create();
    PackageCredential::factory()->for($team)->create(['target' => 'nova.laravel.com', 'secret' => 'team-key-0000']);
    $projectNova = PackageCredential::factory()->for($team)->for($project)->create(['target' => 'NOVA.laravel.com', 'secret' => 'project-key-0000']);
    $teamNpm = PackageCredential::factory()->for($team)->npm()->create();
    PackageCredential::factory()->for($team)->for($other)->python()->create();

    $chosen = collect(app(PackageCredentials::class)->forApplication(packageCredentialsApp($team, $project)));

    expect($chosen->pluck('id')->sort()->values()->all())->toBe(collect([$projectNova->id, $teamNpm->id])->sort()->values()->all())
        ->and($chosen->firstWhere('id', $projectNova->id)->secret)->toBe('project-key-0000');
});

test('the secret is encrypted at rest, hidden from arrays and hinted by its last four characters', function () {
    $credential = PackageCredential::factory()->create(['secret' => 'nova-license-key-9f2c']);
    $raw = DB::table('package_credentials')->where('id', $credential->id)->value('secret');

    expect($raw)->not->toContain('nova-license-key')
        ->and($credential->fresh()->secret)->toBe('nova-license-key-9f2c')
        ->and($credential->fresh()->hint)->toBe('9f2c')
        ->and($credential->fresh()->toArray())->not->toHaveKey('secret');
});
