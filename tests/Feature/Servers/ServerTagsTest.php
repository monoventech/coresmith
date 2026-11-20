<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Http\Controllers\ServerTagsController;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\CommandPalette;
use App\Services\Projects\AppTags;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{team: Team, owner: User, server: Server}
 */
function serverTagsFixture(): array
{
    $team = Team::factory()->owned()->create();

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'server' => Server::factory()->for($team)->create(['name' => 'web']),
    ];
}

function serverTagsPerson(Team $team, TeamRole $role): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => true]);

    return $user->fresh();
}

test('the tags on a server change from its page, cleaned and saved, and the change is recorded', function () {
    ['owner' => $owner, 'server' => $server] = serverTagsFixture();

    $this->actingAs($owner)->put(route('servers.tags.update', $server), ['tags' => ['Production ', 'eu', 'production']])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved.');

    $activity = Activity::query()->where('description', ServerTagsController::CHANGED)->sole();

    expect($server->fresh()->tags)->toBe(['Production', 'eu'])
        ->and($activity->properties->all())->toEqual(['from' => [], 'to' => ['Production', 'eu']])
        ->and(collect(app(ActivityFeed::class)->forServer($server, viewer: $owner))->map(fn(array $entry): string => collect($entry['parts'])->pluck('text')->implode(''))->implode("\n"))->toContain('Tags on web set to Production, eu');
});

test('saving the same server tags records nothing, and clearing them leaves none', function () {
    ['owner' => $owner, 'server' => $server] = serverTagsFixture();
    $server->update(['tags' => ['production']]);

    $this->actingAs($owner)->put(route('servers.tags.update', $server), ['tags' => ['production']])->assertSessionMissing('success');

    expect(Activity::query()->where('description', ServerTagsController::CHANGED)->count())->toBe(0);

    $this->actingAs($owner)->put(route('servers.tags.update', $server), ['tags' => []])->assertSessionHas('success', 'Saved.');

    expect($server->fresh()->tags)->toBe([])
        ->and(Activity::query()->where('description', ServerTagsController::CHANGED)->sole()->properties->all())->toEqual(['from' => ['production'], 'to' => []]);
});

test('server tags are refused when there are too many, one is too long or one starts oddly', function () {
    ['owner' => $owner, 'server' => $server] = serverTagsFixture();
    $route = route('servers.tags.update', $server);

    $this->actingAs($owner)->put($route, ['tags' => ['a', 'b', 'c', 'd', 'e', 'f']])->assertSessionHasErrors(['tags' => 'A server can have up to 5 tags.']);
    $this->actingAs($owner)->put($route, ['tags' => [str_repeat('x', 25)]])->assertSessionHasErrors(['tags.0' => 'Keep each tag to 24 characters or fewer.']);
    $this->actingAs($owner)->put($route, ['tags' => ['#ops']])->assertSessionHasErrors(['tags.0' => 'Start each tag with a letter or number, then use letters, numbers, spaces, dots, dashes or underscores.']);

    expect($server->fresh()->tags)->toBe([]);
});

test('app tags still say app when there are too many', function () {
    expect(AppTags::messages()['tags.max'])->toBe('An app can have up to 5 tags.');
});

test('people who cannot change the server cannot change its tags', function () {
    ['team' => $team, 'server' => $server] = serverTagsFixture();
    $member = serverTagsPerson($team, TeamRole::Member);

    $this->actingAs($member)->put(route('servers.tags.update', $server), ['tags' => ['mine']])->assertForbidden();

    expect($server->fresh()->tags)->toBe([]);
});

test('the server page shows its tags to everyone and offers editing, with the tags in use on servers, only to people who can change it', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server] = serverTagsFixture();
    $server->update(['tags' => ['production', 'eu']]);
    Server::factory()->for($team)->create(['name' => 'db', 'tags' => ['Staging', 'Production']]);
    $member = serverTagsPerson($team, TeamRole::Member);

    $this->actingAs($owner)->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('server.tags', ['production', 'eu'])
            ->where('server.tags_href', route('servers.tags.update', $server))
            ->where('server.tag_suggestions', ['eu', 'production', 'Staging']));
    $this->actingAs($owner)->get(route('servers.hardening', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('server.tags', ['production', 'eu']));
    $this->actingAs($member)->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('server.tags', ['production', 'eu'])
            ->where('server.tags_href', null)
            ->where('server.tag_suggestions', []));
});

test('the explorer, Fleet and the palette show a server tags', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server] = serverTagsFixture();
    $server->update(['tags' => ['production', 'eu']]);

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('explorer.servers.0.tags', ['production', 'eu'])
            ->where('servers.0.tags', ['production', 'eu']));

    $open = collect((new CommandPalette($owner, $team))->commands())->firstWhere('label', 'Open web');

    expect($open['hint'] ?? '')->toStartWith('production · eu · ');
});

test('the API lists each server with its tags', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server] = serverTagsFixture();
    $server->update(['tags' => ['production']]);
    $token = $owner->createToken('tags', [Ability::ViewServer->value]);
    ApiToken::whereKey($token->accessToken->getKey())->update(['team_id' => $team->getKey()]);
    $headers = ['Authorization' => 'Bearer ' . $token->plainTextToken, 'Accept' => 'application/json'];

    $this->withHeaders($headers)->getJson('/api/v1/servers')->assertOk()->assertJsonPath('data.0.tags', ['production']);
    $this->withHeaders($headers)->getJson("/api/v1/servers/{$server->id}")->assertOk()->assertJsonPath('data.tags', ['production']);
});
