<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use App\Notifications\WeeklyDigestNotification;
use App\Services\Teams\TeamAccess;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

function narrowedAlertsPerson(Team $team, TeamRole $role, bool $everything, array $preferences = []): User
{
    $user = User::factory()->create(['notification_preferences' => $preferences === [] ? null : $preferences]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

function narrowedAlertsNotice(): BaseNotification
{
    return new class extends BaseNotification
    {
        /**
         * @return list<string>
         */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }
    };
}

test("a server alert skips narrowed members who can't see the server", function () {
    Notification::fake();
    $team = Team::factory()->create();
    $web = Server::factory()->for($team)->create();
    $db = Server::factory()->for($team)->create();
    $owner = narrowedAlertsPerson($team, TeamRole::Owner, true);
    $member = narrowedAlertsPerson($team, TeamRole::Member, false);
    app(TeamAccess::class)->grant($team, $member, $web);
    $notice = narrowedAlertsNotice();

    app(TeamNotifier::class)->send($team, Preferences::SERVER_ALERTS, $notice, about: $db);

    Notification::assertSentTo($owner, $notice::class);
    Notification::assertNotSentTo($member, $notice::class);

    app(TeamNotifier::class)->send($team, Preferences::SERVER_ALERTS, $notice, about: $web);

    Notification::assertSentTo($member, $notice::class);
});

test('a deployment failure reaches a member the app is shared with on its own', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $db = Server::factory()->for($team)->create();
    $api = Application::factory()->for($team)->for($db)->create();
    $member = narrowedAlertsPerson($team, TeamRole::Member, false);
    app(TeamAccess::class)->grant($team, $member, $api);
    $notice = narrowedAlertsNotice();

    app(TeamNotifier::class)->send($team, Preferences::DEPLOY_FAILED, $notice, about: $api);

    Notification::assertSentTo($member, $notice::class);
});

test('alerts with no subject still reach everyone who wants them', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $member = narrowedAlertsPerson($team, TeamRole::Member, false);
    $notice = narrowedAlertsNotice();

    app(TeamNotifier::class)->send($team, Preferences::SERVER_ALERTS, $notice);

    Notification::assertSentTo($member, $notice::class);
});

test('the weekly digest counts only what a narrowed member can see', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $web = Server::factory()->for($team)->create();
    $db = Server::factory()->for($team)->create();
    $shop = Application::factory()->for($team)->for($web)->create();
    $api = Application::factory()->for($team)->for($db)->create();
    Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::Succeeded, 'created_at' => now()->subDay()]);
    Deployment::factory()->for($api)->create(['status' => DeploymentStatus::Failed, 'created_at' => now()->subDay()]);
    $member = narrowedAlertsPerson($team, TeamRole::Member, false, [Preferences::WEEKLY_DIGEST => true]);
    app(TeamAccess::class)->grant($team, $member, $web);

    $this->artisan('coresmith:weekly-digest')->assertSuccessful();

    Notification::assertSentTo($member, WeeklyDigestNotification::class, fn(WeeklyDigestNotification $digest): bool => $digest->summary['deployed'] === 1 && $digest->summary['failed'] === 0);
});
