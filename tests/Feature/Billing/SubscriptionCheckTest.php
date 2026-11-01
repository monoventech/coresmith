<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

function subscriptionCheckTeam(): Team
{
    return Team::factory()->subscribed(Plan::Pro)->owned()->create();
}

function subscriptionCheckAddServer(Team $team): TestResponse
{
    return test()->actingAs(User::query()->findOrFail($team->owner_id))
        ->post(route('servers.store'), ['team_id' => $team->id, 'name' => 'web-9', 'ip_address' => '203.0.113.99', 'ssh_port' => 22, 'ssh_user' => 'root']);
}

beforeEach(function () {
    CloudBilling::enable();
    Queue::fake();
});

test('stripe saying the plan ended stops a server being added', function () {
    $ended = now()->subHour()->getTimestamp();
    FakeStripe::fake([FakeStripe::url('subscriptions/*') => FakeStripe::subscription('sub_x', 'cus_x', 'price_test_pro', 'canceled', ['ended_at' => $ended])]);
    $team = subscriptionCheckTeam();

    subscriptionCheckAddServer($team)->assertSessionHas('error', "Stripe says this team's plan is no longer active, so nothing was added. Open Billing settings to see why.");

    $subscription = $team->subscription('default')->fresh();

    expect($team->servers()->count())->toBe(0)
        ->and($subscription->stripe_status)->toBe('canceled')
        ->and($subscription->ends_at->getTimestamp())->toBe($ended);
});

test('a subscription stripe no longer has is treated as ended', function () {
    FakeStripe::fake([FakeStripe::url('subscriptions/*') => FakeStripe::error('invalid_request_error', 'No such subscription', 404, 'resource_missing')]);
    $team = subscriptionCheckTeam();

    subscriptionCheckAddServer($team)->assertSessionHas('error', "Stripe says this team's plan is no longer active, so nothing was added. Open Billing settings to see why.");

    expect($team->servers()->count())->toBe(0)
        ->and($team->subscription('default')->fresh()->ends_at)->not->toBeNull();
});

test('stripe being unreachable does not stop paying teams', function () {
    FakeStripe::fake([FakeStripe::url('subscriptions/*') => Http::failedConnection()]);
    Log::spy();
    $team = subscriptionCheckTeam();

    subscriptionCheckAddServer($team)->assertSessionMissing('error');

    expect($team->servers()->count())->toBe(1);

    Log::shouldHaveReceived('warning')->with('Stripe could not be reached for the subscription check.', ['team' => $team->id]);
});

test('stripe failing does not stop paying teams', function () {
    FakeStripe::fake([FakeStripe::url('subscriptions/*') => Http::response(['error' => ['type' => 'api_error', 'message' => 'Something went wrong.']], 500)]);
    $team = subscriptionCheckTeam();

    subscriptionCheckAddServer($team)->assertSessionMissing('error');

    expect($team->servers()->count())->toBe(1);
});

test('teams without a stripe subscription are not checked', function () {
    FakeStripe::fake();
    $team = Team::factory()->onTrial()->owned()->create();

    subscriptionCheckAddServer($team)->assertSessionMissing('error');

    Http::assertNothingSent();
});

test('applications are checked too', function () {
    FakeStripe::fake([FakeStripe::url('subscriptions/*') => FakeStripe::subscription('sub_x', 'cus_x', 'price_test_pro', 'unpaid')]);
    $team = subscriptionCheckTeam();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    $this->actingAs(User::query()->findOrFail($team->owner_id))
        ->post(route('servers.applications.store', $server), ['name' => 'shop', 'type' => ApplicationType::Php->value, 'repository_branch' => 'main', 'directory' => '/', 'web_directory' => '/public', 'app_size' => 'small'])
        ->assertSessionHas('error', "Stripe says this team's plan is no longer active, so nothing was added. Open Billing settings to see why.");

    expect($server->applications()->count())->toBe(0);
});
