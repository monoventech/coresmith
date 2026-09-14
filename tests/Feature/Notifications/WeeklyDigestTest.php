<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

function digestReader(?Team $team, bool $wantsDigest = true): User
{
    $user = User::factory()->create(['notification_preferences' => ['weekly_digest' => $wantsDigest]]);
    $team?->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user;
}

function digestDeploy(Application $application, DeploymentStatus $status, int $daysAgo): void
{
    Deployment::factory()->for($application)->create(['status' => $status, 'created_at' => now()->subDays($daysAgo)]);
}

function digestEvent(Model $subject, string $description, int $daysAgo): void
{
    activity()->performedOn($subject)->log($description);

    Activity::query()->latest('id')->first()->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00:00'));
});

test('the digest goes only to people who asked for it and belong to a team', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $reader = digestReader($team);
    $notInterested = digestReader($team, wantsDigest: false);
    $defaults = User::factory()->create();
    $team->members()->attach($defaults, ['role' => TeamRole::Member->value]);
    $teamless = digestReader(null);

    $this->artisan('coresmith:weekly-digest')
        ->expectsOutput('Sent 1 digest.')
        ->assertSuccessful();

    Notification::assertSentTo($reader, WeeklyDigestNotification::class);
    Notification::assertNotSentTo([$notInterested, $defaults, $teamless], WeeklyDigestNotification::class);
});

test('the digest summarises the last week of the readers teams only', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();
    $removed = Application::factory()->for($team)->for($server)->create();
    $reader = digestReader($team);

    digestDeploy($application, DeploymentStatus::Succeeded, daysAgo: 1);
    digestDeploy($application, DeploymentStatus::Succeeded, daysAgo: 6);
    digestDeploy($application, DeploymentStatus::Succeeded, daysAgo: 8);
    digestDeploy($application, DeploymentStatus::Failed, daysAgo: 2);
    digestDeploy($application, DeploymentStatus::Cancelled, daysAgo: 2);
    digestDeploy($application, DeploymentStatus::Running, daysAgo: 0);
    digestDeploy($removed, DeploymentStatus::Succeeded, daysAgo: 3);
    $removed->delete();

    digestEvent($application, 'application_rolled_back', daysAgo: 1);
    digestEvent($application, 'application_rolled_back', daysAgo: 10);
    digestEvent($server, 'disk_pressure', daysAgo: 2);
    digestEvent($server, 'disk_pressure', daysAgo: 4);
    digestEvent($server, 'server_rebooted', daysAgo: 1);

    $theirs = Team::factory()->create();
    $theirServer = Server::factory()->for($theirs)->create();
    $theirApplication = Application::factory()->for($theirs)->for($theirServer)->create();
    digestDeploy($theirApplication, DeploymentStatus::Succeeded, daysAgo: 1);
    digestDeploy($theirApplication, DeploymentStatus::Failed, daysAgo: 1);
    digestEvent($theirApplication, 'application_rolled_back', daysAgo: 1);
    digestEvent($theirServer, 'disk_pressure', daysAgo: 1);

    $this->artisan('coresmith:weekly-digest')->assertSuccessful();

    Notification::assertSentTo($reader, WeeklyDigestNotification::class, fn(WeeklyDigestNotification $notification): bool => $notification->summary === [
        'deployed' => 3,
        'failed' => 1,
        'rollbacks' => 1,
        'warnings' => 2,
    ]);
});

test('someone in several teams gets one digest covering all of them', function () {
    Notification::fake();
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $reader = digestReader($first);
    $second->members()->attach($reader, ['role' => TeamRole::Admin->value]);

    digestDeploy(Application::factory()->for($first)->create(), DeploymentStatus::Succeeded, daysAgo: 1);
    digestDeploy(Application::factory()->for($second)->create(), DeploymentStatus::Succeeded, daysAgo: 1);

    $this->artisan('coresmith:weekly-digest')->expectsOutput('Sent 1 digest.')->assertSuccessful();

    Notification::assertSentToTimes($reader, WeeklyDigestNotification::class, 1);
    Notification::assertSentTo($reader, WeeklyDigestNotification::class, fn(WeeklyDigestNotification $notification): bool => $notification->summary['deployed'] === 2);
});

test('with nobody opted in the digest sends nothing and says so', function () {
    Notification::fake();
    digestReader(Team::factory()->create(), wantsDigest: false);

    $this->artisan('coresmith:weekly-digest')->expectsOutput('Sent 0 digests.')->assertSuccessful();

    Notification::assertNothingSent();
});

test('several readers are counted in the summary line', function () {
    Notification::fake();
    $team = Team::factory()->create();
    digestReader($team);
    digestReader($team);

    $this->artisan('coresmith:weekly-digest')->expectsOutput('Sent 2 digests.')->assertSuccessful();
});

test('the digest goes out every monday morning', function () {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'coresmith:weekly-digest');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * 1')
        ->and($event->withoutOverlapping)->toBeTrue();
});
