<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Events\DeploymentFailed;
use App\Jobs\DetectStaleDeployments;
use App\Listeners\NotifyDeploymentFailed;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApplicationToggledNotification;
use App\Notifications\CloudTaskFailedNotification;
use App\Notifications\DeploymentFailedNotification;
use App\Notifications\DiskPressureNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use App\Notifications\WeeklyDigestNotification;
use App\Services\Cloud\Tasks\CloudTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

function notifyMember(Team $team, array $preferences = [], TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create(['notification_preferences' => $preferences === [] ? null : $preferences]);
    $team->members()->attach($user, ['role' => $role->value]);

    return $user;
}

function notifyFailedDeployment(Team $team, string $log): Deployment
{
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Shop']);

    return Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'log' => $log]);
}

test('the failure listener is registered for failed deployments', function () {
    Event::fake();

    Event::assertListening(DeploymentFailed::class, NotifyDeploymentFailed::class);
});

test('a failed deployment emails every member of its team, even one who tried to opt out', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $member = notifyMember($team);
    $optedOut = notifyMember($team, ['deploy_failed' => false]);
    $outsider = notifyMember(Team::factory()->create());
    $deployment = notifyFailedDeployment($team, '[10:00:00] clone: fetching over https');

    DeploymentFailed::dispatch($deployment);

    Notification::assertSentTo([$member, $optedOut], DeploymentFailedNotification::class, fn(DeploymentFailedNotification $notification): bool => $notification->deployment->is($deployment));
    Notification::assertNotSentTo($outsider, DeploymentFailedNotification::class);
});

test('a deployment that timed out on the server notifies the team too', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $member = notifyMember($team);
    $deployment = notifyFailedDeployment($team, '');
    $deployment->forceFill(['status' => DeploymentStatus::Running, 'started_at' => now()->subHour()])->save();

    (new DetectStaleDeployments)->handle();

    Notification::assertSentTo($member, DeploymentFailedNotification::class);
});

test('a failed deployment of an application whose team is gone emails nobody', function () {
    Notification::fake();
    $team = Team::factory()->create();
    notifyMember($team);
    $deployment = notifyFailedDeployment($team, '');
    $team->delete();

    DeploymentFailed::dispatch($deployment->fresh());

    Notification::assertNothingSent();
});

test('the team notifier only reaches members who want that kind of email', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $wanting = notifyMember($team);
    $optedOut = notifyMember($team, ['toggle_changes' => false]);
    $digestOnly = notifyMember($team, ['weekly_digest' => true]);
    $notification = new ApplicationToggledNotification(Application::factory()->for($team)->create(), 'The app was stopped', 'Ada');

    app(TeamNotifier::class)->send($team, Preferences::TOGGLE_CHANGES, $notification);

    Notification::assertSentTo([$wanting, $digestOnly], ApplicationToggledNotification::class);
    Notification::assertNotSentTo($optedOut, ApplicationToggledNotification::class);
});

test('the team notifier leaves out the person who caused the change', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $actor = notifyMember($team, role: TeamRole::Owner);
    $teammate = notifyMember($team);
    $notification = new ApplicationToggledNotification(Application::factory()->for($team)->create(), 'The app was started', $actor->name);

    app(TeamNotifier::class)->send($team, Preferences::TOGGLE_CHANGES, $notification, User::find($actor->id));

    Notification::assertSentTo($teammate, ApplicationToggledNotification::class);
    Notification::assertNotSentTo($actor, ApplicationToggledNotification::class);
});

test('the team notifier sends nothing without a team or without anyone to tell', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $only = notifyMember($team, ['disk_pressure' => false]);
    $notification = new DiskPressureNotification(Server::factory()->for($team)->create(), 90);

    app(TeamNotifier::class)->send(null, Preferences::DISK_PRESSURE, $notification);
    app(TeamNotifier::class)->send($team, Preferences::DISK_PRESSURE, $notification);
    app(TeamNotifier::class)->send(Team::factory()->create(), Preferences::DISK_PRESSURE, $notification);

    Notification::assertNothingSent();
    Notification::assertNotSentTo($only, DiskPressureNotification::class);
});

test('every panel notification is a queued email', function (object $notification) {
    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via(new User))->toBe(['mail']);
})->with([
    'deploy failed' => fn() => new DeploymentFailedNotification(new Deployment),
    'disk pressure' => fn() => new DiskPressureNotification(new Server, 90),
    'toggle changed' => fn() => new ApplicationToggledNotification(new Application, 'The app was stopped', null),
    'weekly digest' => fn() => new WeeklyDigestNotification(['deployed' => 0, 'failed' => 0, 'rollbacks' => 0, 'warnings' => 0]),
]);

test('a failure before the swap is emailed as having changed nothing', function () {
    $team = Team::factory()->create();
    $deployment = notifyFailedDeployment($team, implode("\n", [
        '[10:00:00] clone: fetching over https',
        '[10:00:04] migrate: running migrations against release 20260913100000',
        '[10:00:09] deploy failed. the current release is untouched.',
    ]));

    $mail = (new DeploymentFailedNotification($deployment))->toMail(new User);

    expect($mail->subject)->toBe('Shop failed to deploy, nothing changed')
        ->and($mail->introLines)->toBe([
            'The run migrations step failed on web-1.',
            'The current release kept serving the whole time, so no one saw an error page.',
        ])
        ->and($mail->actionText)->toBe('Read the log')
        ->and($mail->actionUrl)->toBe(route('servers.applications.deployments.show', [$deployment->application->server_id, $deployment->application_id, $deployment]));
});

test('a failure after the swap is emailed as live and needing a look', function () {
    $team = Team::factory()->create();
    $deployment = notifyFailedDeployment($team, implode("\n", [
        '[10:00:00] clone: fetching over https',
        '[10:00:06] swap: current → releases/20260913100000',
        '[10:00:09] deploy failed after the symlink swap. releases/20260913100000 is live, check its processes.',
    ]));

    $mail = (new DeploymentFailedNotification($deployment))->toMail(new User);

    expect($mail->subject)->toBe('Shop failed to deploy')
        ->and($mail->introLines)->toBe([
            'The symlink swap step failed on web-1.',
            'It failed after the switch to the new release, so that release is live. Check its processes.',
        ]);
});

test('a failure with no phase recorded is emailed as a failed deploy', function () {
    $deployment = notifyFailedDeployment(Team::factory()->create(), 'Deployment timed out with no response for 15 minutes.');

    expect((new DeploymentFailedNotification($deployment))->toMail(new User)->introLines[0])->toBe('The deploy step failed on web-1.');
});

test('a disk pressure email names the server, the level and what pruning keeps', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    $mail = (new DiskPressureNotification($server, 86))->toMail(new User);

    expect($mail->subject)->toBe('web-1 disk is at 86%')
        ->and($mail->introLines)->toBe([
            'Deploys stop at 95%, so there is still room, but not much.',
            'Pruning keeps the last 5 releases of each app, plus the live one.',
        ])
        ->and($mail->actionText)->toBe('Open web-1')
        ->and($mail->actionUrl)->toBe(route('servers.show', $server));
});

test('a toggle email says what changed, who changed it and links to the application', function (?string $actor, string $line) {
    $application = Application::factory()->create(['name' => 'Shop']);

    $mail = (new ApplicationToggledNotification($application, 'The app was stopped', $actor))->toMail(new User);

    expect($mail->subject)->toBe('The app was stopped · Shop')
        ->and($mail->introLines)->toBe([$line])
        ->and($mail->actionText)->toBe('Open Shop')
        ->and($mail->actionUrl)->toBe(route('servers.applications.show', [$application->server_id, $application]));
})->with([
    'a named person' => ['Ada Lovelace', 'Ada Lovelace made the change in Coresmith. No deployment was needed.'],
    'nobody named' => [null, 'Someone made the change in Coresmith. No deployment was needed.'],
]);

test('the weekly digest counts in words that match the numbers', function (array $summary, array $lines) {
    $mail = (new WeeklyDigestNotification($summary))->toMail(new User);

    expect($mail->subject)->toBe('Your week on Coresmith')
        ->and($mail->introLines)->toBe($lines)
        ->and($mail->actionText)->toBe('Open the fleet')
        ->and($mail->actionUrl)->toBe(route('dashboard'));
})->with([
    'singular' => [
        ['deployed' => 1, 'failed' => 1, 'rollbacks' => 1, 'warnings' => 1],
        ['1 deploy went live and 1 failed without reaching anyone.', '1 rollback and 1 warning.'],
    ],
    'plural' => [
        ['deployed' => 12, 'failed' => 2, 'rollbacks' => 3, 'warnings' => 0],
        ['12 deploys went live and 2 failed without reaching anyone.', '3 rollbacks and 0 warnings.'],
    ],
]);

test('a failed provider task mail says what failed and why, and links to the server', function (string $type, string $subject) {
    $server = Server::factory()->fromProvider(CloudProvider::factory()->create())->create(['name' => 'outpost-02']);
    $notice = new CloudTaskFailedNotification($server, $type, 'Hetzner Cloud refused the request.');
    $mail = $notice->toMail(new User);

    expect($notice)->toBeInstanceOf(ShouldQueue::class)
        ->and($notice->via(new User))->toBe(['mail'])
        ->and($mail->subject)->toBe($subject)
        ->and($mail->introLines)->toBe(['Hetzner Cloud refused the request.'])
        ->and($mail->actionText)->toBe('Open outpost-02')
        ->and($mail->actionUrl)->toBe(route('servers.show', $server));
})->with([
    'a creation' => [CloudTask::CREATE, 'Creating outpost-02 at Hetzner Cloud failed'],
    'a destroy' => [CloudTask::DESTROY, 'Destroying outpost-02 at Hetzner Cloud failed'],
    'a resize' => [CloudTask::RESIZE, 'Resizing outpost-02 at Hetzner Cloud failed'],
]);
