<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\BillingState;
use App\Enums\Plan;
use App\Enums\ReadOnlyReason;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\PlanRefusal;
use App\Http\Middleware\EnforcePlan;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamInvitation;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Billing\BillingStatus;
use App\Services\Webhook\SignatureVerifier;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CloudBilling;

function readOnlyTrialMessage(): string
{
    return "This team's trial has ended, so it is read-only. Servers and apps keep running. Choose a plan in Billing settings to make changes again.";
}

function readOnlyTeam(): Team
{
    $team = Team::factory()->trialEnded(3)->owned()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    Application::factory()->for($team)->for($server)->create(['repository_branch' => 'main']);

    return $team->fresh();
}

function readOnlyOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function readOnlyApplication(Team $team): Application
{
    return $team->applications()->firstOrFail();
}

function readOnlyToken(User $user, Team $team, array $abilities): string
{
    $token = $user->createToken('test', array_map(fn(Ability $ability): string => $ability->value, $abilities));
    ApiToken::whereKey($token->accessToken->getKey())->update(['team_id' => $team->getKey()]);

    return $token->plainTextToken;
}

function readOnlyPush(WebhookEndpoint $endpoint, array $payload, array $headers): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => (new SignatureVerifier)->expected($body, 'github', (string) $endpoint->secret)];

    foreach ($headers as $name => $value) {
        $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
    }

    return test()->call('POST', route('webhooks.deploy', $endpoint), [], [], [], $server, $body);
}

beforeEach(function () {
    CloudBilling::enable();
    Queue::fake();
});

test('a read-only team can look at everything', function () {
    $team = readOnlyTeam();
    $application = readOnlyApplication($team);
    $this->actingAs(readOnlyOwner($team));

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('servers.show', $application->server))->assertOk();
    $this->get(route('servers.applications.show', [$application->server, $application]))->assertOk();
    $this->get(route('settings.general'))->assertOk();
    $this->get(route('teams.index'))->assertOk();
});

test('a read-only team cannot change anything on the web', function (Closure $write) {
    $team = readOnlyTeam();
    $this->actingAs(readOnlyOwner($team));

    $write($this, $team, readOnlyApplication($team))
        ->assertRedirect()
        ->assertSessionHas('error', readOnlyTrialMessage());

    Queue::assertNothingPushed();
})->with([
    'deploy' => [fn($test, Team $team, Application $app) => $test->post(route('servers.applications.deploy', [$app->server, $app]))],
    'environment' => [fn($test, Team $team, Application $app) => $test->put(route('servers.applications.env.update', [$app->server, $app]), ['env' => "APP_ENV=production\n"])],
    'maintenance' => [fn($test, Team $team, Application $app) => $test->put(route('servers.applications.maintenance', [$app->server, $app]), ['enabled' => true])],
    'a new server' => [fn($test, Team $team, Application $app) => $test->post(route('servers.store'), ['team_id' => $team->id, 'name' => 'web-2', 'ip_address' => '203.0.113.20'])],
    'a database' => [fn($test, Team $team, Application $app) => $test->post(route('servers.databases.store', $app->server), ['name' => 'shop'])],
    'a teammate' => [fn($test, Team $team, Application $app) => $test->post(route('teams.members.store', $team), ['email' => 'new@example.com', 'role' => 'member'])],
    'an ssh key' => [fn($test, Team $team, Application $app) => $test->post(route('ssh-keys.store'), ['name' => 'laptop', 'public_key' => 'ssh-ed25519 AAAA'])],
    'an api token' => [fn($test, Team $team, Application $app) => $test->post(route('api-tokens.store'), ['name' => 'ci', 'abilities' => [Ability::DeployApplication->value]])],
]);

test('a read-only team cannot change anything through the api', function () {
    $team = readOnlyTeam();
    $application = readOnlyApplication($team);
    $headers = ['Authorization' => 'Bearer ' . readOnlyToken(readOnlyOwner($team), $team, [Ability::DeployApplication, Ability::UpdateApplication]), 'Accept' => 'application/json'];

    $this->postJson(route('api.applications.deploy', $application), [], $headers)
        ->assertStatus(402)
        ->assertExactJson(['message' => readOnlyTrialMessage(), 'reason' => 'read_only']);
    $this->putJson(route('api.applications.maintenance', $application), ['enabled' => true], $headers)
        ->assertStatus(402)
        ->assertJson(['reason' => 'read_only']);

    Queue::assertNothingPushed();
});

test('a read-only team can still leave, secure itself, shrink and pay', function (Closure $request) {
    Http::fake();
    $team = readOnlyTeam();
    $owner = readOnlyOwner($team);
    $this->actingAs($owner);

    $response = $request($this, $team, $owner);

    expect($response->getStatusCode())->not->toBe(402)
        ->and(session('error'))->not->toBe(readOnlyTrialMessage());
})->with([
    'sign out' => [fn($test, Team $team, User $owner) => $test->post(route('logout'))],
    'switch team' => [fn($test, Team $team, User $owner) => $test->put(route('current-team.update'), ['team_id' => $team->id])],
    'remove an ssh key' => [fn($test, Team $team, User $owner) => $test->delete(route('ssh-keys.destroy', SshKey::factory()->for($owner)->create()))],
    'revoke an api token' => [fn($test, Team $team, User $owner) => $test->delete(route('api-tokens.destroy', $owner->createToken('old')->accessToken->getKey()))],
    'remove a teammate' => [function ($test, Team $team, User $owner) {
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        return $test->delete(route('teams.members.destroy', [$team, $member]));
    }],
    'revoke an invitation' => [fn($test, Team $team, User $owner) => $test->delete(route('teams.invitations.destroy', [$team, TeamInvitation::factory()->for($team)->create()]))],
    'remove the domain' => [function ($test, Team $team, User $owner) {
        TeamDomain::factory()->for($team)->create();

        return $test->delete(route('settings.team-domain.destroy'));
    }],
    'remove single sign-on' => [function ($test, Team $team, User $owner) {
        TeamSsoConnection::factory()->for($team)->create();

        return $test->delete(route('settings.sso.destroy'));
    }],
    'disconnect a git provider' => [fn($test, Team $team, User $owner) => $test->delete(route('source-providers.destroy', SourceProvider::factory()->for($team)->create()))],
    'remove a github app' => [fn($test, Team $team, User $owner) => $test->delete(route('github-apps.destroy', GitHubApp::factory()->withoutWebhook()->for($team)->create()))],
    'disconnect a cloud provider' => [fn($test, Team $team, User $owner) => $test->delete(route('cloud-providers.destroy', CloudProvider::factory()->for($team)->create()))],
    'destroy a provider server' => [function ($test, Team $team, User $owner) {
        $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
        $server->forceFill(['cloud_provider_id' => CloudProvider::factory()->for($team)->create()->getKey(), 'provider_server_id' => '4711'])->save();

        return $test->delete(route('servers.cloud.destroy', $server));
    }],
    'detach a server' => [fn($test, Team $team, User $owner) => $test->delete(route('servers.destroy', $team->servers()->firstOrFail()))],
]);

test('detaching a server works while read-only', function () {
    $team = readOnlyTeam();
    $server = $team->servers()->firstOrFail();

    $this->actingAs(readOnlyOwner($team))->delete(route('servers.destroy', $server));

    expect(Server::query()->find($server->id))->toBeNull()
        ->and(Server::withTrashed()->find($server->id)?->trashed())->toBeTrue();
});

test("a push to a read-only team's webhook is refused and can be delivered again later", function () {
    $team = readOnlyTeam();
    $application = readOnlyApplication($team);
    $application->forceFill(['quick_deploy' => true])->save();
    $endpoint = WebhookEndpoint::factory()->for($application)->create(['secret' => 'shhh-secret', 'provider' => 'github', 'active' => true]);
    $payload = ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)];
    $headers = ['X-GitHub-Event' => 'push', 'X-GitHub-Delivery' => 'push-while-read-only'];

    readOnlyPush($endpoint, $payload, $headers)
        ->assertStatus(409)
        ->assertExactJson(['message' => 'This team is read-only, so the push was not deployed.']);

    expect(Deployment::query()->count())->toBe(0);

    $team->forceFill(['trial_ends_at' => now()->addDays(5)])->save();

    readOnlyPush($endpoint, $payload, $headers)->assertStatus(202);

    expect(Deployment::query()->count())->toBe(1);
});

test('a read-only team is not auto-deployed', function () {
    $team = readOnlyTeam();
    $application = readOnlyApplication($team);
    $application->forceFill(['quick_deploy' => true])->save();

    expect(app(AutoDeploy::class)->observe($application->fresh(), str_repeat('c', 40), 'push'))->toBeNull()
        ->and(Deployment::query()->count())->toBe(0);
});

test('connecting a git provider counts as a change', function (Closure $visit) {
    $team = readOnlyTeam();
    $this->actingAs(readOnlyOwner($team));

    $visit($this)->assertRedirect()->assertSessionHas('error', readOnlyTrialMessage());
})->with([
    'a provider' => [fn($test) => $test->get(route('source-providers.connect', 'github'))],
    'a github app coming back' => [fn($test) => $test->get(route('github-apps.registered', ['code' => 'abc', 'state' => 'xyz']))],
]);

test('the refusal says why and who can fix it', function (ReadOnlyReason $reason, bool $canManage, string $message) {
    $refusal = PlanRefusal::readOnly(new BillingStatus(BillingState::ReadOnly, null, $reason), $canManage);

    expect($refusal->getMessage())->toBe($message)
        ->and($refusal->reason)->toBe('read_only')
        ->and($refusal->status)->toBe(402);
})->with([
    'no plan, owner' => [ReadOnlyReason::NoPlan, true, 'This team has no plan yet, so it is read-only. Servers and apps keep running. Choose a plan in Billing settings to make changes again.'],
    'no plan, deployer' => [ReadOnlyReason::NoPlan, false, 'This team has no plan yet, so it is read-only. Servers and apps keep running. Ask an owner to choose a plan.'],
    'trial ended, owner' => [ReadOnlyReason::TrialEnded, true, readOnlyTrialMessage()],
    'trial ended, deployer' => [ReadOnlyReason::TrialEnded, false, "This team's trial has ended, so it is read-only. Servers and apps keep running. Ask an owner to choose a plan."],
    'plan ended, owner' => [ReadOnlyReason::PlanEnded, true, "This team's plan has ended, so it is read-only. Servers and apps keep running. Choose a plan in Billing settings to make changes again."],
    'plan ended, deployer' => [ReadOnlyReason::PlanEnded, false, "This team's plan has ended, so it is read-only. Servers and apps keep running. Ask an owner to choose a plan."],
    'payment failed, owner' => [ReadOnlyReason::PaymentFailed, true, "This team's payments failed, so it is read-only. Servers and apps keep running. Update the payment method in Billing settings to make changes again."],
    'payment failed, deployer' => [ReadOnlyReason::PaymentFailed, false, "This team's payments failed, so it is read-only. Servers and apps keep running. Ask an owner to update the payment method."],
]);

test('a deployer is told to ask an owner', function () {
    $team = readOnlyTeam();
    $deployer = CloudBilling::member($team, TeamRole::Member);
    $deployer->forceFill(['current_team_id' => $team->id])->save();
    $application = readOnlyApplication($team);

    $this->actingAs($deployer)
        ->post(route('servers.applications.deploy', [$application->server, $application]))
        ->assertSessionHas('error', "This team's trial has ended, so it is read-only. Servers and apps keep running. Ask an owner to choose a plan.");
});

test('a team named by the address is the one that is checked', function () {
    $paying = Team::factory()->subscribed(Plan::Pro)->owned()->create(['slug' => 'paying']);
    $payingServer = Server::factory()->for($paying)->create(['status' => ServerStatus::Active]);
    $payingApp = Application::factory()->for($paying)->for($payingServer)->create();
    $owner = readOnlyOwner($paying);
    $lapsed = Team::factory()->trialEnded(3)->create(['slug' => 'lapsed', 'owner_id' => $owner->id]);
    $lapsed->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $lapsedServer = Server::factory()->for($lapsed)->create(['status' => ServerStatus::Active]);
    $lapsedApp = Application::factory()->for($lapsed)->for($lapsedServer)->create();
    $this->actingAs($owner->fresh());

    $this->post('/lapsed' . route('servers.applications.deploy', [$lapsedServer, $lapsedApp], false))
        ->assertSessionHas('error', readOnlyTrialMessage());

    Queue::assertNothingPushed();

    $this->post('/paying' . route('servers.applications.deploy', [$payingServer, $payingApp], false))
        ->assertSessionMissing('error');

    expect(Deployment::query()->where('application_id', $payingApp->id)->count())->toBe(1);
});

test('self-hosted is never read-only', function () {
    config(['coresmith.edition' => 'self-hosted']);
    $team = readOnlyTeam();
    $application = readOnlyApplication($team);

    $this->actingAs(readOnlyOwner($team))
        ->post(route('servers.applications.deploy', [$application->server, $application]))
        ->assertSessionMissing('error');

    expect(Deployment::query()->count())->toBe(1);
});

test('every allowlisted route exists and changes something', function () {
    foreach (EnforcePlan::OPEN_WHILE_READ_ONLY as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("{$name} does not exist")
            ->and(array_diff($route->methods(), ['GET', 'HEAD']))->not->toBeEmpty("{$name} changes nothing");
    }
});

test('the plan check sits on every web request and every api write', function () {
    expect(app(Router::class)->getMiddlewareGroups()['web'])->toContain(EnforcePlan::class);

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/') && array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
            expect($route->gatherMiddleware())->toContain('plan');
        }
    }
});
