<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Jobs\DeployApplication;
use App\Jobs\SyncApplicationRoute;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Tasks\CloudTask;
use Illuminate\Support\Facades\Queue;

function apiUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

/**
 * @param  list<Ability>  $abilities
 */
function tokenFor(User $user, Team $team, array $abilities): string
{
    $token = $user->createToken('test', array_map(fn(Ability $a): string => $a->value, $abilities));

    ApiToken::whereKey($token->accessToken->getKey())->update(['team_id' => $team->getKey()]);

    return $token->plainTextToken;
}

function asToken(string $token): array
{
    return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
}

test('an unauthenticated request is refused', function () {
    $this->getJson('/api/v1/servers')->assertUnauthorized();
});

test('a token with the right ability lists only its own teams servers', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    $other->members()->attach($user, ['role' => TeamRole::Owner->value]);

    Server::factory()->for($team)->create(['name' => 'ours']);
    Server::factory()->for($other)->create(['name' => 'theirs']);

    $response = $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewServer])))
        ->getJson('/api/v1/servers')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('ours');
});

test('a server still being created is listed without an address', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    Server::factory()->for($team)->creating()->create();

    $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewServer])))
        ->getJson('/api/v1/servers')
        ->assertOk()
        ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'ip_address', 'status']]])
        ->assertJsonPath('data.0.ip_address', null)
        ->assertJsonPath('data.0.status', 'creating');
});

test('a token bound to one team cannot reach another teams server even when the user belongs to both', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    $other->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $server = Server::factory()->for($other)->create();

    $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewServer])))
        ->getJson('/api/v1/servers/' . $server->getKey())
        ->assertNotFound();
});

test('a token without the ability is refused even though the user has the role', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    Server::factory()->for($team)->create();

    $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewApplication])))
        ->getJson('/api/v1/servers')
        ->assertForbidden()
        ->assertJsonPath('message', 'This token does not carry the server.view ability.');
});

test('a token cannot grant an ability the users role does not have', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $member = apiUser($team, TeamRole::Member);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();

    $this->withHeaders(asToken(tokenFor($member, $team, [Ability::UpdateApplication])))
        ->putJson('/api/v1/applications/' . $application->getKey() . '/maintenance', ['enabled' => true])
        ->assertForbidden()
        ->assertJsonPath('message', 'Your role in this team does not allow that, so no token can.');

    expect((array) $application->fresh()->toggles)->not->toHaveKey('maintenance_mode');
    Queue::assertNothingPushed();
});

test('a member may deploy, because that is what the member role is for', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $member = apiUser($team, TeamRole::Member);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();

    $this->withHeaders(asToken(tokenFor($member, $team, [Ability::DeployApplication])))
        ->postJson('/api/v1/applications/' . $application->getKey() . '/deploy')
        ->assertStatus(202);

    Queue::assertPushed(DeployApplication::class);
    expect(Deployment::count())->toBe(1);
});

test('an admin token can put an application into maintenance', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $admin = apiUser($team, TeamRole::Admin);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();

    $this->withHeaders(asToken(tokenFor($admin, $team, [Ability::UpdateApplication])))
        ->putJson('/api/v1/applications/' . $application->getKey() . '/maintenance', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.maintenance_mode', true);

    Queue::assertPushed(SyncApplicationRoute::class);
});

test('the maintenance endpoint refuses a value that is not a boolean', function () {
    $team = Team::factory()->create();
    $admin = apiUser($team, TeamRole::Admin);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();

    $this->withHeaders(asToken(tokenFor($admin, $team, [Ability::UpdateApplication])))
        ->putJson('/api/v1/applications/' . $application->getKey() . '/maintenance', ['enabled' => 'maybe'])
        ->assertStatus(422);
});

test('an admin token carrying deploy actually deploys', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $admin = apiUser($team, TeamRole::Admin);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();

    $this->withHeaders(asToken(tokenFor($admin, $team, [Ability::DeployApplication])))
        ->postJson('/api/v1/applications/' . $application->getKey() . '/deploy')
        ->assertStatus(202)
        ->assertJsonPath('data.application_id', $application->getKey());

    Queue::assertPushed(DeployApplication::class);
    expect(Deployment::first()->triggered_by)->toBe($admin->getKey());
});

test('a read only token cannot deploy', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $admin = apiUser($team, TeamRole::Admin);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();

    $this->withHeaders(asToken(tokenFor($admin, $team, [Ability::ViewApplication])))
        ->postJson('/api/v1/applications/' . $application->getKey() . '/deploy')
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a token with no team is refused rather than defaulting to one', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    Server::factory()->for($team)->create();

    $plain = $user->createToken('teamless', [Ability::ViewServer->value])->plainTextToken;

    $this->withHeaders(asToken($plain))
        ->getJson('/api/v1/servers')
        ->assertForbidden()
        ->assertJsonPath('message', 'This token is not bound to a team. Issue a new one.');
});

test('a token whose user was removed from the team stops working', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    Server::factory()->for($team)->create();

    $token = tokenFor($user, $team, [Ability::ViewServer]);

    $this->withHeaders(asToken($token))->getJson('/api/v1/servers')->assertOk();

    $team->members()->detach($user);
    $this->app['auth']->forgetGuards();

    $this->withHeaders(asToken($token))->getJson('/api/v1/servers')->assertForbidden();
});

test('a revoked token stops working', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);

    $token = tokenFor($user, $team, [Ability::ViewServer]);

    $this->withHeaders(asToken($token))->getJson('/api/v1/servers')->assertOk();

    $user->tokens()->delete();
    $this->app['auth']->forgetGuards();

    $this->withHeaders(asToken($token))->getJson('/api/v1/servers')->assertUnauthorized();
});

test('a garbled token is refused', function (string $value) {
    $this->withHeaders(asToken($value))->getJson('/api/v1/servers')->assertUnauthorized();
})->with(['nonsense', '1|nonsense', '', 'Bearer']);

test('the session cookie alone cannot reach the api', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create();

    $this->actingAs(apiUser($team, TeamRole::Owner))
        ->getJson('/api/v1/servers')
        ->assertForbidden()
        ->assertJsonPath('message', 'This endpoint requires an API token.');
});

test('the application payload never carries environment variables or deploy keys', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create([
        'env_variables' => '{"SECRET":"do-not-leak"}',
        'deploy_key_private' => '-----BEGIN OPENSSH PRIVATE KEY-----leak',
    ]);

    $response = $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewApplication])))
        ->getJson('/api/v1/applications/' . $application->getKey())
        ->assertOk();

    expect($response->getContent())->not->toContain('do-not-leak')
        ->and($response->getContent())->not->toContain('BEGIN OPENSSH PRIVATE KEY');
});

test('the server payload never carries the private key', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    $server = Server::factory()->for($team)->create();

    $response = $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewServer])))
        ->getJson('/api/v1/servers/' . $server->getKey())
        ->assertOk();

    expect($response->getContent())->not->toContain('BEGIN')
        ->and($response->json('data'))->not->toHaveKey('ssh_private_key');
});

test('deployment logs are reachable for the right team only', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();
    $deployment = Deployment::factory()->for($application)->create(['log' => 'composer install']);

    $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewApplication])))
        ->getJson("/api/v1/applications/{$application->getKey()}/deployments/{$deployment->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.log', 'composer install');

    $outsider = apiUser(Team::factory()->create(), TeamRole::Owner);
    $this->app['auth']->forgetGuards();

    $this->withHeaders(asToken(tokenFor($outsider, $outsider->teams()->first(), [Ability::ViewApplication])))
        ->getJson("/api/v1/applications/{$application->getKey()}/deployments/{$deployment->getKey()}")
        ->assertNotFound();
});

test('a deployment belonging to a different application is not reachable', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    $server = Server::factory()->for($team)->create();
    $a = Application::factory()->for($team)->for($server)->create();
    $b = Application::factory()->for($team)->for($server)->create();
    $deployment = Deployment::factory()->for($b)->create();

    $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewApplication])))
        ->getJson("/api/v1/applications/{$a->getKey()}/deployments/{$deployment->getKey()}")
        ->assertNotFound();
});

test('the page size cannot be inflated past the cap', function () {
    $team = Team::factory()->create();
    $user = apiUser($team, TeamRole::Owner);
    Server::factory()->for($team)->count(3)->create();

    $this->withHeaders(asToken(tokenFor($user, $team, [Ability::ViewServer])))
        ->getJson('/api/v1/servers?per_page=5000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

function apiBusy(Server $server, string $type = CloudTask::RESIZE): void
{
    $server->forceFill(['cloud_provider_id' => CloudProvider::factory()->for($server->team)->create()->getKey(), 'provider_server_id' => '4711'])->save();
    CloudTask::start($server, $type, $type === CloudTask::RESIZE ? 'power_off' : 'delete', $type === CloudTask::RESIZE ? ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true] : []);
}

test('an API deploy waits while the server is being resized', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $admin = apiUser($team, TeamRole::Admin);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team)->create(['name' => 'outpost-02']))->create();
    apiBusy($application->server);

    $this->withHeaders(asToken(tokenFor($admin, $team, [Ability::DeployApplication])))
        ->postJson('/api/v1/applications/' . $application->getKey() . '/deploy')
        ->assertStatus(409)
        ->assertExactJson(['message' => "Coresmith is resizing outpost-02 at Hetzner Cloud. Deploy once it's back."]);

    expect(Deployment::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});
