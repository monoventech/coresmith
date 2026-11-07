<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Panel\SetupProgress;

test('a brand new team starts on connecting github', function () {
    $progress = new SetupProgress(Team::factory()->create());

    expect($progress->done())->toBe([false, false, false, false])
        ->and($progress->complete())->toBeFalse()
        ->and($progress->current())->toBe(0)
        ->and($progress->summary())->toBe(['complete' => false, 'current' => 0, 'total' => 4]);
});

test('each step is done from what the team actually has', function () {
    $team = Team::factory()->create();

    SourceProvider::factory()->for($team)->create();
    expect((new SetupProgress($team))->done())->toBe([true, false, false, false])
        ->and((new SetupProgress($team))->current())->toBe(1);

    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Provisioning]);
    expect((new SetupProgress($team))->done())->toBe([true, true, false, false])
        ->and((new SetupProgress($team))->current())->toBe(2);

    $server->update(['status' => ServerStatus::Active]);
    expect((new SetupProgress($team))->done())->toBe([true, true, true, false])
        ->and((new SetupProgress($team))->current())->toBe(3);

    $application = Application::factory()->for($team)->for($server)->create();
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded]);

    $progress = new SetupProgress($team);

    expect($progress->done())->toBe([true, true, true, true])
        ->and($progress->complete())->toBeTrue()
        ->and($progress->current())->toBe(3)
        ->and($progress->summary())->toBe(['complete' => true, 'current' => 3, 'total' => 4]);
});

test('the current step is the first unfinished one even when later steps are done', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    expect((new SetupProgress($team))->done())->toBe([false, true, true, false])
        ->and((new SetupProgress($team))->current())->toBe(0);
});

test('a deploy that did not succeed does not finish the first deploy step', function (DeploymentStatus $status) {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();
    Deployment::factory()->for($application)->create(['status' => $status]);

    expect((new SetupProgress($team))->done()[3])->toBeFalse();
})->with([DeploymentStatus::Failed, DeploymentStatus::Running, DeploymentStatus::Cancelled, DeploymentStatus::Pending]);

test('another teams resources never count towards setup', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    SourceProvider::factory()->for($other)->create();
    $server = Server::factory()->for($other)->create(['status' => ServerStatus::Active]);
    Deployment::factory()->for(Application::factory()->for($other)->for($server))->create(['status' => DeploymentStatus::Succeeded]);

    expect((new SetupProgress($team))->done())->toBe([false, false, false, false]);
});
