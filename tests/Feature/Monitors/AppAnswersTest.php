<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\MonitorKind;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\CheckAppsAnswering;
use App\Models\AppCheckDay;
use App\Models\Application;
use App\Models\Monitor;
use App\Models\MonitorAlert;
use App\Models\Server;
use App\Models\User;
use App\Notifications\AppAnsweringNotification;
use App\Notifications\AppNotAnsweringNotification;
use App\Services\Monitors\AppAnswers;
use App\Services\Panel\ActivityFeed;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function appAnswersServer(array $attributes = []): Server
{
    return Server::factory()->create(['name' => 'web-1', 'status' => ServerStatus::Active, 'ip_address' => '203.0.113.10', ...$attributes]);
}

function appAnswersApp(Server $server, array $attributes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'status' => ApplicationStatus::Active,
        'power_state' => PowerState::Active,
        'answered_at' => now()->subDay(),
        ...$attributes,
    ]);
}

function appAnswersMember(Server $server, array $preferences = []): User
{
    $user = User::factory()->create(['notification_preferences' => $preferences === [] ? null : $preferences]);
    $server->team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user;
}

function appAnswersCheck(Server $server, int ...$statuses): void
{
    foreach ($statuses as $status) {
        test()->answer = $status;
        app(AppAnswers::class)->check($server->fresh());
        test()->travel(AppAnswers::EVERY_MINUTES)->minutes();
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00'));
    Notification::fake();
    $this->answer = 200;
    Http::fake(fn(Request $request) => $this->answer === 0 ? Http::failedConnection() : Http::response('', $this->answer));
});

test('an app that fails two checks in a row opens one alert and mails it once', function () {
    $server = appAnswersServer();
    $shop = appAnswersApp($server);
    $member = appAnswersMember($server);

    appAnswersCheck($server, 502);

    Notification::assertNothingSent();

    appAnswersCheck($server, 502, 503);

    Notification::assertSentToTimes($member, AppNotAnsweringNotification::class, 1);
    Notification::assertSentTo($member, AppNotAnsweringNotification::class, fn(AppNotAnsweringNotification $notification): bool => $notification->application->is($shop) && $notification->status === 502 && $notification->checks === 2 && $notification->domain === 'shop.example.com');
    expect(MonitorAlert::query()->sole()->application_id)->toBe($shop->id)
        ->and(Activity::query()->where('description', 'app_not_answering')->sole()->properties->toArray())->toBeInAnyKeyOrder(['domain' => 'shop.example.com', 'status' => 502, 'checks' => 2]);
});

test('a domain that does not answer at all is named as not answering', function () {
    $server = appAnswersServer();
    appAnswersApp($server);
    $member = appAnswersMember($server);

    appAnswersCheck($server, 0, 0);

    Notification::assertSentTo($member, AppNotAnsweringNotification::class, fn(AppNotAnsweringNotification $notification): bool => $notification->status === null);
    expect(Activity::query()->where('description', 'app_not_answering')->sole()->properties['status'])->toBeNull();
});

test('a single failed check between answers raises nothing, and every check is counted for the day', function () {
    $server = appAnswersServer();
    $shop = appAnswersApp($server);
    appAnswersMember($server);

    appAnswersCheck($server, 200, 502, 200, 0, 200);

    Notification::assertNothingSent();
    expect(MonitorAlert::query()->exists())->toBeFalse()
        ->and(AppCheckDay::query()->sole()->only(['application_id', 'checks', 'answered']))->toBe(['application_id' => $shop->id, 'checks' => 5, 'answered' => 3])
        ->and(AppCheckDay::query()->sole()->last_failure_at->toDateTimeString())->toBe('2026-11-15 09:15:00');
});

test('the next answer closes the alert and says how long the app was failing', function () {
    $server = appAnswersServer();
    $shop = appAnswersApp($server);
    $member = appAnswersMember($server);

    appAnswersCheck($server, 502, 502, 502, 200);

    $recovered = Notification::sent($member, AppAnsweringNotification::class)->sole();

    expect($recovered->minutes)->toBe(15)
        ->and(MonitorAlert::query()->sole()->open_key)->toBeNull()
        ->and($shop->fresh()->failed_checks)->toBe(0)
        ->and(Activity::query()->where('description', 'app_answering')->sole()->properties->toArray())->toBe(['minutes' => 15]);

    appAnswersCheck($server, 200);

    Notification::assertSentTimes(AppAnsweringNotification::class, 1);
});

test('answers under the limit count, and Any error moves the line to 400', function (?int $line, int $status, bool $alerted) {
    $server = appAnswersServer();
    appAnswersApp($server);
    appAnswersMember($server);

    if ($line !== null) {
        Monitor::query()->create(['team_id' => $server->team_id, 'kind' => MonitorKind::Apps, 'threshold' => $line, 'duration' => 2, 'enabled' => true]);
    }

    appAnswersCheck($server, $status, $status);

    expect(MonitorAlert::query()->exists())->toBe($alerted);
})->with([
    'a redirect, by default' => [null, 302, false],
    'not found, by default' => [null, 404, false],
    'a server error, by default' => [null, 500, true],
    'not found, with any error' => [400, 404, true],
    'a redirect, with any error' => [400, 301, false],
]);

test('a team that asks for three checks in a row gets its alert on the third', function () {
    $server = appAnswersServer();
    appAnswersApp($server);
    $member = appAnswersMember($server);
    Monitor::query()->create(['team_id' => $server->team_id, 'kind' => MonitorKind::Apps, 'threshold' => 500, 'duration' => 3, 'enabled' => true]);

    appAnswersCheck($server, 502, 502);

    Notification::assertNothingSent();

    appAnswersCheck($server, 502);

    Notification::assertSentTo($member, AppNotAnsweringNotification::class, fn(AppNotAnsweringNotification $notification): bool => $notification->checks === 3);
});

test('apps that are not meant to answer are not checked, and their open alerts close quietly', function (array $attributes) {
    $server = appAnswersServer();
    $resting = appAnswersApp($server, $attributes);
    appAnswersMember($server);
    $alert = MonitorAlert::open($server, MonitorKind::Apps, $resting);

    appAnswersCheck($server, 502, 502);

    Http::assertNothingSent();
    Notification::assertNothingSent();
    expect($alert->fresh()->open_key)->toBeNull()
        ->and(AppCheckDay::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'app_answering')->exists())->toBeFalse();
})->with([
    'stopped' => [['status' => ApplicationStatus::Stopped]],
    'never deployed' => [['status' => ApplicationStatus::Pending]],
    'asleep' => [['power_state' => PowerState::Sleeping]],
    'powered off' => [['power_state' => PowerState::Stopped]],
    'in maintenance' => [['toggles' => ['maintenance_mode' => true]]],
    'a preview' => [['preview' => true]],
    'only on the tailnet' => [['tailnet' => true]],
    'without a domain' => [['domain' => null]],
]);

test('an app that has never answered is not failing, only not set up yet', function () {
    $server = appAnswersServer();
    $shop = appAnswersApp($server, ['answered_at' => null]);
    appAnswersMember($server);

    appAnswersCheck($server, 0, 0, 0);

    Notification::assertNothingSent();
    expect(AppCheckDay::query()->exists())->toBeFalse();

    appAnswersCheck($server, 200, 502, 502);

    expect($shop->fresh()->answered_at)->not->toBeNull()
        ->and(AppCheckDay::query()->sole()->only(['checks', 'answered']))->toBe(['checks' => 3, 'answered' => 1]);
    Notification::assertSentTimes(AppNotAnsweringNotification::class, 1);
});

test('the apps on a server Coresmith cannot reach are left unchecked, not failed', function () {
    $server = appAnswersServer(['connection_failures' => 3]);
    appAnswersApp($server);
    appAnswersMember($server);

    appAnswersCheck($server, 0, 0);

    Http::assertNothingSent();
    expect(AppCheckDay::query()->exists())->toBeFalse()
        ->and(MonitorAlert::query()->exists())->toBeFalse();
});

test('turning the apps monitor off stops the checks and closes open alerts without a mail', function () {
    $server = appAnswersServer();
    appAnswersApp($server);
    appAnswersMember($server);
    appAnswersCheck($server, 502, 502);

    Monitor::query()->create(['team_id' => $server->team_id, 'kind' => MonitorKind::Apps, 'threshold' => 500, 'duration' => 2, 'enabled' => false]);
    appAnswersCheck($server, 502);

    Http::assertSentCount(2);
    expect(MonitorAlert::query()->sole()->open_key)->toBeNull();
    Notification::assertSentTimes(AppAnsweringNotification::class, 0);
});

test('each check asks the app on its own server for its first domain, over https, without following redirects', function () {
    $server = appAnswersServer();
    appAnswersApp($server, ['domain' => 'shop.example.com', 'additional_domains' => ['www.shop.example.com']]);

    appAnswersCheck($server, 301);

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://shop.example.com/' && $request->method() === 'GET' && $request->header('User-Agent') === ['Coresmith uptime check']);
    expect(AppAnswers::resolve($server, 'shop.example.com'))->toBe(['shop.example.com:443:203.0.113.10'])
        ->and(AppAnswers::resolve(appAnswersServer(['ip_address' => '2001:db8::10']), 'shop.example.com'))->toBe(['shop.example.com:443:[2001:db8::10]'])
        ->and(AppAnswers::resolve(appAnswersServer(['ip_address' => 'not-an-address']), 'shop.example.com'))->toBe([]);
});

test('an alert that cannot be mailed leaves no alert and is tried again at the next check', function () {
    Exceptions::fake();
    $server = appAnswersServer();
    appAnswersApp($server);
    $member = appAnswersMember($server);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));

    appAnswersCheck($server, 502, 502);

    expect(MonitorAlert::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'app_not_answering')->exists())->toBeFalse();
    Exceptions::assertReportedCount(1);

    Notification::fake();
    appAnswersCheck($server, 502);

    Notification::assertSentTo($member, AppNotAnsweringNotification::class);
});

test('a removed app drops its open alert quietly', function () {
    $server = appAnswersServer();
    $shop = appAnswersApp($server);
    appAnswersMember($server);
    appAnswersCheck($server, 502, 502);

    $shop->delete();

    expect(MonitorAlert::query()->sole()->open_key)->toBeNull();
    Notification::assertSentTimes(AppAnsweringNotification::class, 0);
});

test('the mails say what failed, how often, and how long it lasted', function (object $notification, string $subject, array $lines) {
    $mail = $notification->toMail(new User);

    expect($mail->subject)->toBe($subject)
        ->and($mail->introLines)->toBe($lines)
        ->and($mail->actionText)->toBe('Open Shop');
})->with([
    'an error' => [fn() => new AppNotAnsweringNotification(appAnswersApp(appAnswersServer()), 'shop.example.com', 502, 2), "Shop isn't answering", ['shop.example.com answered with 502 two checks in a row, 5 minutes apart.', "The live log on the app's page shows what it printed. You'll get one more email when it answers again."]],
    'no answer' => [fn() => new AppNotAnsweringNotification(appAnswersApp(appAnswersServer()), 'shop.example.com', null, 3), "Shop isn't answering", ["shop.example.com didn't answer three checks in a row, 5 minutes apart.", "The live log on the app's page shows what it printed. You'll get one more email when it answers again."]],
    'back after minutes' => [fn() => new AppAnsweringNotification(appAnswersApp(appAnswersServer()), 15), 'Shop is answering again', ['It was failing checks for about 15 minutes.']],
    'back after hours' => [fn() => new AppAnsweringNotification(appAnswersApp(appAnswersServer()), 125), 'Shop is answering again', ['It was failing checks for about 2 hours 5 minutes.']],
]);

test('the feed says when an app stopped answering and when it came back', function (string $description, array $properties, string $sentence) {
    $shop = appAnswersApp(appAnswersServer());

    activity()->performedOn($shop)->withProperties($properties)->log($description);

    $row = (new ActivityFeed)->forTeam($shop->team)[0];

    expect(implode('', array_column($row['parts'], 'text')))->toBe($sentence);
})->with([
    'an error' => ['app_not_answering', ['domain' => 'shop.example.com', 'status' => 502, 'checks' => 2], 'Shop answered 502 at shop.example.com'],
    'no answer' => ['app_not_answering', ['domain' => 'shop.example.com', 'status' => null, 'checks' => 2], 'Shop stopped answering at shop.example.com'],
    'back' => ['app_answering', ['minutes' => 15], 'Shop answering again after 15 minutes'],
]);

test('the check runs every five minutes for each active server with apps, one at a time per server', function () {
    Queue::fake();
    $server = appAnswersServer();
    appAnswersApp($server);
    appAnswersServer(['name' => 'empty-1']);
    appAnswersApp(appAnswersServer(['name' => 'off-1', 'status' => ServerStatus::Provisioning]), ['slug' => 'shop-two', 'domain' => 'two.example.com']);

    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'coresmith:check-apps');
    $event->run(app());

    expect($event->expression)->toBe('*/5 * * * *');
    Queue::assertPushed(CheckAppsAnswering::class, 1);
    Queue::assertPushed(CheckAppsAnswering::class, fn(CheckAppsAnswering $job): bool => $job->uniqueId() === $server->id);
});
