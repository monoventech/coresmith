<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{team: Team, web: Server, db: Server, shop: Application, api: Application, deployment: Deployment, member: User}
 */
function narrowedApiSetup(TeamRole $role = TeamRole::Member): array
{
    $team = Team::factory()->create();
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $db = Server::factory()->for($team)->create(['name' => 'db']);
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop']);
    $api = Application::factory()->for($team)->for($db)->create(['name' => 'api']);
    $deployment = Deployment::factory()->for($api)->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => $role->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($team, $member, $web);

    return ['team' => $team, 'web' => $web, 'db' => $db, 'shop' => $shop, 'api' => $api, 'deployment' => $deployment, 'member' => $member->fresh()];
}

/**
 * @param  list<Ability>  $abilities
 * @return array<string, string>
 */
function narrowedApiHeaders(User $user, Team $team, array $abilities): array
{
    $token = $user->createToken('narrowed', array_map(fn(Ability $ability): string => $ability->value, $abilities));
    ApiToken::whereKey($token->accessToken->getKey())->update(['team_id' => $team->getKey()]);

    return ['Authorization' => 'Bearer ' . $token->plainTextToken, 'Accept' => 'application/json'];
}

test("a narrowed member's token lists only shared servers and apps", function () {
    ['team' => $team, 'member' => $member] = narrowedApiSetup();
    $headers = narrowedApiHeaders($member, $team, [Ability::ViewServer, Ability::ViewApplication]);

    expect(collect($this->withHeaders($headers)->getJson('/api/v1/servers')->assertOk()->json('data'))->pluck('name')->all())->toBe(['web'])
        ->and(collect($this->withHeaders($headers)->getJson('/api/v1/applications')->assertOk()->json('data'))->pluck('name')->all())->toBe(['shop']);
});

test('a narrowed admin cannot read, deploy or put into maintenance an app not shared with them', function () {
    Queue::fake();
    ['team' => $team, 'db' => $db, 'api' => $api, 'member' => $member] = narrowedApiSetup(TeamRole::Admin);
    $headers = narrowedApiHeaders($member, $team, [Ability::ViewServer, Ability::ViewApplication, Ability::DeployApplication, Ability::UpdateApplication]);

    $this->withHeaders($headers)->getJson("/api/v1/servers/{$db->id}")->assertNotFound();
    $this->withHeaders($headers)->getJson("/api/v1/applications/{$api->id}")->assertNotFound();
    $this->withHeaders($headers)->postJson("/api/v1/applications/{$api->id}/deploy")->assertNotFound();
    $this->withHeaders($headers)->putJson("/api/v1/applications/{$api->id}/maintenance", ['enabled' => true])->assertNotFound();

    Queue::assertNothingPushed();
});

test('its deployments endpoints answer 404 for apps not shared with them', function () {
    ['team' => $team, 'api' => $api, 'deployment' => $deployment, 'member' => $member] = narrowedApiSetup();
    $headers = narrowedApiHeaders($member, $team, [Ability::ViewApplication]);

    $this->withHeaders($headers)->getJson("/api/v1/applications/{$api->id}/deployments")->assertNotFound();
    $this->withHeaders($headers)->getJson("/api/v1/applications/{$api->id}/deployments/{$deployment->id}")->assertNotFound();
});
