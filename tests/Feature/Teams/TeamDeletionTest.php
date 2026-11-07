<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\Plan;
use App\Enums\ReadOnlyReason;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\BackupDestination;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\SourceProvider;
use App\Models\SsoIdentity;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamInvitation;
use App\Models\TeamInvoice;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Billing\TeamBilling;
use App\Services\Billing\TrialHistory;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Teams\TeamSlug;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

beforeEach(function () {
    CloudBilling::enable();
    FakeStripe::fake();
});

function teamDeletionTeam(array $attributes = []): Team
{
    return Team::factory()->onTrial()->owned()->create(['name' => 'Acme Labs', ...$attributes]);
}

function teamDeletionAsk(User $user, string $name = 'Acme Labs'): TestResponse
{
    return test()->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('settings.general'))
        ->delete(route('teams.destroy'), ['name' => $name]);
}

function teamDeletionCancel(Subscription $subscription): void
{
    FakeStripe::fake([
        FakeStripe::url("subscriptions/{$subscription->stripe_id}*") => Http::response(FakeStripe::subscription(
            $subscription->stripe_id,
            (string) $subscription->owner->stripe_id,
            (string) $subscription->stripe_price,
            'canceled',
            ['canceled_at' => now()->getTimestamp(), 'ended_at' => now()->getTimestamp()],
        )),
    ]);
}

test('an owner alone on a trial team deletes it and lands on the page for people with no team', function () {
    $team = teamDeletionTeam();

    teamDeletionAsk(CloudBilling::owner($team))
        ->assertRedirect('/start')
        ->assertSessionHas('team_deleted', true);

    expect(Team::withTrashed()->find($team->id))->toBeNull();
    Http::assertNothingSent();
});

test('an owner with another team lands on it and is told the team was deleted', function () {
    $team = teamDeletionTeam();
    $owner = CloudBilling::owner($team);
    $other = Team::factory()->create(['name' => 'Second Shop', 'slug' => 'second-shop', 'owner_id' => $owner->id]);
    $other->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    teamDeletionAsk($owner)
        ->assertRedirect('/second-shop/dashboard')
        ->assertSessionHas('success', 'Deleted Acme Labs.');

    expect($owner->fresh()->current_team_id)->toBe($other->id);
});

test('a plan that renews is refused until it is ended', function (string $status) {
    $team = Team::factory()->subscribed(Plan::Pro, $status)->owned()->create(['name' => 'Acme Labs']);

    teamDeletionAsk(CloudBilling::owner($team))
        ->assertRedirect(route('settings.general'))
        ->assertSessionHas('error', 'End the plan from Billing first, then delete the team.');

    expect(Team::find($team->id))->not->toBeNull();
    Http::assertNothingSent();
})->with(['active', 'past_due', 'trialing']);

test('a plan set to end is cancelled at Stripe before the team goes', function () {
    $team = Team::factory()->owned()->create(['name' => 'Acme Labs', 'stripe_id' => 'cus_acme']);
    $subscription = Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->ending(now()->addDays(9))->create(['stripe_id' => 'sub_acme']);
    teamDeletionCancel($subscription);

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');

    Http::assertSent(fn(ClientRequest $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'subscriptions/sub_acme'));
    expect(Team::withTrashed()->find($team->id))->toBeNull()
        ->and(Subscription::query()->whereKey($subscription->id)->exists())->toBeFalse();
});

test('a plan Stripe does not cancel leaves the team as it was and says so', function () {
    $team = Team::factory()->owned()->create(['name' => 'Acme Labs', 'stripe_id' => 'cus_acme']);
    $subscription = Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->ending(now()->addDays(9))->create(['stripe_id' => 'sub_acme']);
    $server = Server::factory()->for($team)->create();
    FakeStripe::fake([
        FakeStripe::url('subscriptions/sub_acme*') => Http::response(['error' => ['type' => 'api_error', 'message' => 'Something went wrong on our end.']], 500),
    ]);

    teamDeletionAsk(CloudBilling::owner($team))
        ->assertRedirect(route('settings.general'))
        ->assertSessionHas('error', 'Stripe could not cancel the plan, so nothing was deleted. Try again in a minute.');

    expect(Team::find($team->id))->not->toBeNull()
        ->and(Server::find($server->id))->not->toBeNull()
        ->and($subscription->fresh()->ends_at)->not->toBeNull();
});

test('a lapsed trial, a plan that ended and a payment that failed are deleted without asking Stripe to cancel anything', function (Closure $lapse, string $reason) {
    $team = Team::factory()->owned()->create(['name' => 'Acme Labs', 'stripe_id' => 'cus_acme']);
    $lapse($team);

    expect(app(TeamBilling::class)->status($team->fresh())->reason)->toBe(ReadOnlyReason::from($reason));

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');

    expect(Team::withTrashed()->find($team->id))->toBeNull();
    Http::assertNothingSent();
})->with([
    'a lapsed trial' => [fn(Team $team) => $team->forceFill(['trial_ends_at' => now()->subDays(3)])->save(), 'trial_ended'],
    'a plan that ended' => [fn(Team $team) => Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->ended(now()->subDays(2))->create(), 'plan_ended'],
    'a plan whose end passed before Stripe said so' => [fn(Team $team) => Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->create(['ends_at' => now()->subDay()]), 'plan_ended'],
    'an expired first payment' => [fn(Team $team) => Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->status('incomplete_expired')->create(), 'payment_failed'],
]);

test('an unpaid plan is cancelled at Stripe so it stops raising invoices', function () {
    $team = Team::factory()->owned()->create(['name' => 'Acme Labs', 'stripe_id' => 'cus_acme']);
    $subscription = Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->status('unpaid')->create(['stripe_id' => 'sub_unpaid']);
    teamDeletionCancel($subscription);

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');

    Http::assertSent(fn(ClientRequest $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'subscriptions/sub_unpaid'));
});

test('a team with other members is refused', function (TeamRole $role) {
    $team = teamDeletionTeam();
    CloudBilling::member($team, $role);

    teamDeletionAsk(CloudBilling::owner($team))
        ->assertSessionHas('error', 'Remove the other members first, so nobody loses access by surprise.');

    expect(Team::find($team->id))->not->toBeNull();
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member]);

test('provider work still in progress is refused', function (string $type, string $step, string $doing) {
    $team = teamDeletionTeam();
    $account = CloudProvider::factory()->for($team)->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-01', 'cloud_provider_id' => $account->id, 'provider_server_id' => '4242']);
    CloudTask::start($server, $type, $step);

    teamDeletionAsk(CloudBilling::owner($team))
        ->assertSessionHas('error', "Coresmith is still {$doing} web-01 at Hetzner Cloud. Delete the team once that finishes.");

    expect(Team::find($team->id))->not->toBeNull();
})->with([
    'a creation' => [CloudTask::CREATE, 'create', 'creating'],
    'a resize' => [CloudTask::RESIZE, 'change', 'resizing'],
    'a destroy' => [CloudTask::DESTROY, 'delete', 'destroying'],
]);

test('provider work that finished or failed does not hold the team back', function (string $state) {
    $team = teamDeletionTeam();
    $server = Server::factory()->for($team)->create();
    $server->forceFill(['provider_task' => ['type' => CloudTask::RESIZE, 'state' => $state, 'step' => 'change']])->save();

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');
})->with([CloudTask::DONE, CloudTask::FAILED]);

test('a deploy still in progress is refused', function (DeploymentStatus $status) {
    $team = teamDeletionTeam();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['name' => 'shop']);
    Deployment::factory()->for($application)->create(['status' => $status]);

    teamDeletionAsk(CloudBilling::owner($team))
        ->assertSessionHas('error', 'shop is still deploying. Delete the team once the deploy finishes.');

    expect(Team::find($team->id))->not->toBeNull();
})->with([DeploymentStatus::Pending, DeploymentStatus::Running]);

test('deleting a team detaches its servers and never asks a provider to destroy one', function () {
    $team = teamDeletionTeam();
    $account = CloudProvider::factory()->for($team)->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'cloud_provider_id' => $account->id, 'provider_server_id' => '4242']);
    $plain = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');

    expect(Server::withTrashed()->whereKey([$server->id, $plain->id])->exists())->toBeFalse()
        ->and(CloudProvider::withTrashed()->whereKey($account->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('deleting a team leaves its id in no table but the trial history', function () {
    $team = teamDeletionTeam();
    $owner = CloudBilling::owner($team);
    $elsewhere = Team::factory()->owned()->create(['name' => 'Elsewhere']);
    $kept = Server::factory()->for($elsewhere)->create();
    activity()->performedOn($kept)->log('server_created');

    $server = Server::factory()->for($team)->create();
    $gone = Server::factory()->for($team)->create(['deleted_at' => now()]);
    $application = Application::factory()->for($team)->for($server)->create();
    $removed = Application::factory()->for($team)->for($server)->create(['deleted_at' => now()]);
    $deployment = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded]);
    $webhook = WebhookEndpoint::factory()->for($application)->create();
    $metric = ServerMetric::factory()->for($server)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create();
    ManagedDatabase::factory()->for($team)->for($server)->create(['deleted_at' => now()]);
    $source = SourceProvider::factory()->for($team)->create(['type' => 'custom']);
    $account = CloudProvider::factory()->for($team)->create();
    $subscription = Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->ended(now()->subDays(2))->create();
    TeamInvoice::factory()->for($team)->create();
    TeamDomain::factory()->for($team)->create();
    TeamSsoConnection::factory()->for($team)->create();
    SsoIdentity::factory()->for($team)->for($owner)->create();
    $app = GitHubApp::factory()->for($team)->create();
    BackupDestination::factory()->for($team)->create();
    TeamInvitation::factory()->for($team)->create();
    $owner->createToken('ci');
    ApiToken::query()->where('tokenable_id', $owner->id)->update(['team_id' => $team->id]);
    app(TrialHistory::class)->claim((string) $owner->email, $team);

    $subjects = [$team, $server, $gone, $application, $removed, $database, $webhook, $source, $account];
    foreach ($subjects as $subject) {
        activity()->performedOn($subject)->causedBy($owner)->log('something_happened');
    }
    activity()->performedOn($app)->withProperties(['team_id' => $team->id])->log('github_app_registered');

    teamDeletionAsk($owner)->assertRedirect('/start');

    $tables = collect(Schema::getTables())->pluck('name')->filter(fn(string $table): bool => Schema::hasColumn($table, 'team_id'))->values();
    $holding = $tables->reject(fn(string $table): bool => $table === 'trial_history')
        ->filter(fn(string $table): bool => DB::table($table)->where('team_id', $team->id)->exists())
        ->values()
        ->all();

    expect($holding)->toBe([])
        ->and($tables->all())->toContain('servers', 'applications', 'databases', 'source_providers', 'cloud_providers', 'subscriptions', 'team_invoices', 'team_user', 'team_domains', 'team_sso_connections', 'sso_identities', 'github_apps', 'backup_destinations', 'team_invitations', 'personal_access_tokens', 'trial_history')
        ->and(DB::table('trial_history')->where('team_id', $team->id)->exists())->toBeTrue()
        ->and(User::query()->where('current_team_id', $team->id)->exists())->toBeFalse()
        ->and(Deployment::query()->whereKey($deployment->id)->exists())->toBeFalse()
        ->and(WebhookEndpoint::query()->whereKey($webhook->id)->exists())->toBeFalse()
        ->and(ServerMetric::query()->whereKey($metric->id)->exists())->toBeFalse()
        ->and(DB::table('subscription_items')->where('subscription_id', $subscription->id)->exists())->toBeFalse()
        ->and(Activity::query()->whereIn('subject_id', [...collect($subjects)->map->getKey()->all(), $app->id])->exists())->toBeFalse()
        ->and(Server::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_id', $kept->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

test('the provider catalogs cached for the team\'s accounts are forgotten', function () {
    $team = teamDeletionTeam();
    $account = CloudProvider::factory()->for($team)->create();
    $removed = CloudProvider::factory()->for($team)->create(['deleted_at' => now()]);
    Cache::put(CloudCatalog::cacheKey($account), ['regions' => []], 600);
    Cache::put(CloudCatalog::cacheKey($removed), ['regions' => []], 600);

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');

    expect(Cache::has(CloudCatalog::cacheKey($account)))->toBeFalse()
        ->and(Cache::has(CloudCatalog::cacheKey($removed)))->toBeFalse();
});

test('a deleted team\'s address is free for a new team', function () {
    $team = teamDeletionTeam(['slug' => 'acme-labs']);

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');

    expect(TeamSlug::isAvailable('acme-labs'))->toBeTrue();
});

test('the team\'s git tokens are revoked once it is gone', function () {
    Http::fake(['gitlab.com/oauth/revoke' => Http::response([])]);
    $team = teamDeletionTeam();
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'access_token' => 'to-be-revoked', 'refresh_token' => 'also-gone']);

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');

    Http::assertSent(fn(ClientRequest $request): bool => str_contains($request->url(), 'gitlab.com/oauth/revoke') && $request['token'] === 'to-be-revoked');
    expect(SourceProvider::withTrashed()->where('team_id', $team->id)->exists())->toBeFalse();
});

test('a git host that refuses the revoke does not stop the deletion', function () {
    Http::fake(['gitlab.com/oauth/revoke' => Http::response(['error' => 'invalid_token'], 401)]);
    $team = teamDeletionTeam();
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'access_token' => 'to-be-revoked']);

    teamDeletionAsk(CloudBilling::owner($team))->assertRedirect('/start');

    expect(Team::withTrashed()->find($team->id))->toBeNull();
});

test('the deletion is logged with ids and nothing else', function () {
    Log::spy();
    $team = teamDeletionTeam();
    $owner = CloudBilling::owner($team);

    teamDeletionAsk($owner)->assertRedirect('/start');

    Log::shouldHaveReceived('info')->with('A team was deleted.', ['team' => $team->id, 'owner' => $owner->id, 'by' => $owner->id])->once();
});

test('the password must have been confirmed recently', function () {
    $team = teamDeletionTeam();

    $this->actingAs(CloudBilling::owner($team))
        ->from(route('settings.general'))
        ->delete(route('teams.destroy'), ['name' => 'Acme Labs'])
        ->assertRedirect(route('password.confirm'));

    expect(Team::find($team->id))->not->toBeNull();
});

test('the team\'s name must be typed exactly', function (string $typed, string $error) {
    $team = teamDeletionTeam();

    teamDeletionAsk(CloudBilling::owner($team), $typed)
        ->assertRedirect(route('settings.general'))
        ->assertSessionHasErrors(['name' => $error]);

    expect(Team::find($team->id))->not->toBeNull();
})->with([
    'nothing' => ['', 'Type Acme Labs to delete it.'],
    'another case' => ['acme labs', "That isn't the team's name. Type Acme Labs exactly to delete it."],
    'part of it' => ['Acme', "That isn't the team's name. Type Acme Labs exactly to delete it."],
]);

test('only an owner can delete the team', function (TeamRole $role) {
    $team = teamDeletionTeam();
    $user = CloudBilling::member($team, $role);
    $user->forceFill(['current_team_id' => $team->id])->save();

    teamDeletionAsk($user)->assertForbidden();

    expect(Team::find($team->id))->not->toBeNull();
})->with([TeamRole::Admin, TeamRole::Member]);

test('self-hosted panels have no team deletion', function () {
    config(['coresmith.edition' => 'self-hosted']);
    $team = teamDeletionTeam();

    teamDeletionAsk(CloudBilling::owner($team))->assertNotFound();

    expect(Team::find($team->id))->not->toBeNull();
});

test('an owner who deleted their only team can then close their account', function () {
    $team = teamDeletionTeam();
    $owner = CloudBilling::owner($team);

    teamDeletionAsk($owner)->assertRedirect('/start');

    $this->actingAs($owner->fresh())
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect(User::find($owner->id))->toBeNull();
});

test('settings show the owner what deleting the team would do', function () {
    $team = teamDeletionTeam();
    $account = CloudProvider::factory()->for($team)->create();
    $digitalOcean = CloudProvider::factory()->digitalocean()->for($team)->create();
    Server::factory()->for($team)->create(['cloud_provider_id' => $account->id, 'provider_server_id' => '1']);
    Server::factory()->for($team)->create(['cloud_provider_id' => $digitalOcean->id, 'provider_server_id' => '2']);
    Server::factory()->for($team)->create(['cloud_provider_id' => $account->id, 'provider_server_id' => null]);
    Server::factory()->for($team)->create(['name' => 'web-09', 'cloud_provider_id' => $account->id, 'provider_server_id' => null, 'provider_task' => ['type' => CloudTask::CREATE, 'state' => CloudTask::FAILED, 'step' => 'create', 'data' => ['uncertain' => 1]]]);
    Server::factory()->for($team)->create();
    Server::factory()->for($team)->create(['deleted_at' => now()]);

    $this->actingAs(CloudBilling::owner($team))
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('team_deletion', [
            'name' => 'Acme Labs',
            'servers' => 5,
            'provider_servers' => 2,
            'providers' => ['Hetzner Cloud', 'DigitalOcean'],
            'unconfirmed' => [['name' => 'web-09', 'provider' => 'Hetzner Cloud']],
            'refusal' => null,
        ]));
});

test('settings show why the team cannot be deleted yet', function () {
    $team = teamDeletionTeam();
    CloudBilling::member($team);

    $this->actingAs(CloudBilling::owner($team))
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('team_deletion.refusal', 'Remove the other members first, so nobody loses access by surprise.'));
});

test('settings offer team deletion to owners on cloud only', function (string $edition, TeamRole $role) {
    config(['coresmith.edition' => $edition]);
    $team = teamDeletionTeam();
    $user = $role === TeamRole::Owner ? CloudBilling::owner($team) : CloudBilling::member($team, $role);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($user)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('team_deletion', null));
})->with([
    'an admin on cloud' => ['cloud', TeamRole::Admin],
    'a deployer on cloud' => ['cloud', TeamRole::Member],
    'an owner on a self-hosted panel' => ['self-hosted', TeamRole::Owner],
]);
