<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\ApplicationRelinker;
use App\Services\Git\GitHub\GitHubAppRegistrar;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\GitHubConnector;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, developer: User, other: User, own: SourceProvider, team_connection: SourceProvider}
 */
function ownGitHubSetup(): array
{
    $team = Team::factory()->create();
    $people = [];

    foreach (['owner' => TeamRole::Owner, 'developer' => TeamRole::Developer, 'other' => TeamRole::Developer] as $key => $role) {
        $people[$key] = User::factory()->create();
        $team->members()->attach($people[$key], ['role' => $role->value, 'sees_everything' => $role === TeamRole::Owner]);
        $people[$key] = $people[$key]->fresh();
    }

    $app = GitHubApp::factory()->create(['team_id' => $team->id, 'user_id' => $people['developer']->id]);
    $own = SourceProvider::factory()->githubApp($app)->create(['team_id' => $team->id, 'user_id' => $people['developer']->id, 'name' => 'lena-b']);
    $teamConnection = SourceProvider::factory()->githubApp(GitHubApp::factory()->create(['team_id' => $team->id]))->create(['team_id' => $team->id, 'name' => 'monoventech']);

    return ['team' => $team, 'own' => $own, 'team_connection' => $teamConnection, ...$people];
}

test("a member's own GitHub connection is used only by that member, whatever the others' roles", function () {
    ['team' => $team, 'owner' => $owner, 'developer' => $developer, 'other' => $other, 'own' => $own, 'team_connection' => $teamConnection] = ownGitHubSetup();

    expect([$own->usableBy($developer, $team), $own->usableBy($owner, $team), $own->usableBy($other, $team)])->toBe([true, false, false])
        ->and([$teamConnection->usableBy($owner, $team), $teamConnection->usableBy($developer, $team)])->toBe([true, false]);
});

test("a member's own connection is not theirs in another team", function () {
    ['developer' => $developer, 'own' => $own] = ownGitHubSetup();
    $elsewhere = Team::factory()->create();
    $elsewhere->members()->attach($developer, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);

    expect($own->usableBy($developer, $elsewhere))->toBeFalse();
});

function ownGitHubPem(): string
{
    static $pem = null;

    if ($pem === null) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    }

    return $pem;
}

function ownGitHubFakeConversion(string $key): void
{
    Http::fake([
        'api.github.com/app-manifests/c0de/conversions' => Http::response([
            'id' => 2207131,
            'slug' => 'coresmith-for-lena',
            'name' => 'Coresmith for Lena',
            'client_id' => 'Iv1.fedcba9876543210',
            'client_secret' => 'cs-own-secret',
            'webhook_secret' => 'whs-own-secret',
            'pem' => ownGitHubPem(),
            'owner' => ['login' => 'lena-b', 'type' => 'User'],
            'html_url' => 'https://github.com/apps/coresmith-for-lena',
            'permissions' => ['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'],
            'events' => ['push'],
        ], 201),
        'api.github.com/app/hook/config' => Http::response(['url' => "https://panel.example.com/api/webhooks/github/{$key}", 'content_type' => 'json', 'secret' => '********', 'insecure_ssl' => '0']),
    ]);
}

test('a developer registers a GitHub App of its own, which it alone owns, and its install begins', function () {
    config(['app.url' => 'https://panel.example.com']);
    ['team' => $team, 'developer' => $developer] = ownGitHubSetup();
    GitHubApp::query()->where('user_id', $developer->id)->delete();
    $this->actingAs($developer);
    $intent = app(GitHubAppRegistrar::class)->intent($team, $developer, personal: true);
    ownGitHubFakeConversion($intent['key']);

    $this->get(route('github-apps.registered', ['code' => 'c0de', 'state' => $intent['state']]))
        ->assertRedirect(route('source-providers.connect', ['type' => 'github_app', 'app' => $intent['key']]));

    expect(GitHubApp::find($intent['key']))->user_id->toBe($developer->id)->team_id->toBe($team->id);
});

test('a developer cannot complete a registration of the team\'s own app', function () {
    ['team' => $team, 'developer' => $developer] = ownGitHubSetup();
    $this->actingAs($developer);
    $intent = app(GitHubAppRegistrar::class)->intent($team, $developer);
    ownGitHubFakeConversion($intent['key']);

    $this->get(route('github-apps.registered', ['code' => 'c0de', 'state' => $intent['state']]))->assertForbidden();

    expect(GitHubApp::find($intent['key']))->toBeNull();
});

test('a member keeps one GitHub App of its own per team', function () {
    ['team' => $team, 'developer' => $developer] = ownGitHubSetup();
    $this->actingAs($developer);
    $intent = app(GitHubAppRegistrar::class)->intent($team, $developer, personal: true);
    ownGitHubFakeConversion($intent['key']);

    $this->from(route('account.git'))->get(route('github-apps.registered', ['code' => 'c0de', 'state' => $intent['state']]))
        ->assertRedirect()
        ->assertSessionHas('error', "Your own GitHub is already connected to {$team->name}. Remove it in Your account, Git, to connect another.");

    expect(GitHubApp::find($intent['key']))->toBeNull();
    Http::assertNothingSent();
});

test("the team's GitHub Apps never include a member's own", function () {
    ['team' => $team, 'developer' => $developer] = ownGitHubSetup();

    expect(collect(app(GitHubApps::class)->available($team))->pluck('key')->all())->toBe(GitHubApp::query()->where('team_id', $team->id)->whereNull('user_id')->pluck('id')->all())
        ->and(app(GitHubApps::class)->own($team, $developer)?->key)->toBe(GitHubApp::query()->where('user_id', $developer->id)->value('id'));
});

test('connecting through a GitHub App of its own is open only to the member who owns it', function (string $who, int $status) {
    $setup = ownGitHubSetup();
    $key = GitHubApp::query()->where('user_id', $setup['developer']->id)->value('id');

    $this->actingAs($setup[$who])->get(route('source-providers.connect', ['type' => 'github_app', 'app' => $key]))->assertStatus($status);
})->with([
    'its owner' => ['developer', 302],
    'another developer' => ['other', 404],
    'the team owner' => ['owner', 404],
]);

test('the installation of a GitHub App of its own becomes a connection that member owns', function () {
    ['team' => $team, 'developer' => $developer] = ownGitHubSetup();
    $app = GitHubApp::query()->where('user_id', $developer->id)->sole();
    SourceProvider::query()->where('user_id', $developer->id)->forceDelete();

    $connection = app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($app), ['id' => '77001', 'account' => 'lena-b', 'account_type' => 'User', 'repository_selection' => 'selected', 'suspended_at' => null]);

    expect($connection->user_id)->toBe($developer->id)
        ->and($connection->usableBy($developer, $team))->toBeTrue();
});

test("the team Git page lists members' own GitHub apart from the team's, for those who manage the team", function () {
    ['team' => $team, 'owner' => $owner, 'developer' => $developer, 'own' => $own] = ownGitHubSetup();

    $this->actingAs($owner)->get(route('source-providers.index'))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('github.connections', fn($connections): bool => collect($connections)->pluck('account')->all() === ['monoventech'])
            ->where('github.members', [['id' => $own->id, 'account' => 'lena-b', 'member' => $developer->name, 'remove_href' => route('source-providers.members.destroy', $own)]]));
});

test("an owner removes a member's own GitHub, keys and all, where a developer cannot", function () {
    ['owner' => $owner, 'developer' => $developer, 'other' => $other, 'own' => $own] = ownGitHubSetup();

    $this->actingAs($other)->delete(route('source-providers.members.destroy', $own))->assertForbidden();
    $this->actingAs($owner)->delete(route('source-providers.members.destroy', $own))->assertSessionHas('success');

    expect($own->fresh()->trashed())->toBeTrue()
        ->and(GitHubApp::query()->where('user_id', $developer->id)->exists())->toBeFalse();
});

test('a member who leaves the team takes its own GitHub with it, and its apps say the connection is gone', function () {
    ['team' => $team, 'owner' => $owner, 'developer' => $developer, 'own' => $own] = ownGitHubSetup();
    $application = Application::factory()->for($team)->create(['source_provider_id' => $own->id, 'repository_id' => '881', 'created_by' => $developer->id]);

    $this->actingAs($owner)->delete(route('teams.members.destroy', [$team, $developer]))->assertSessionHas('success', 'Member removed.');

    expect($own->fresh()->trashed())->toBeTrue()
        ->and(GitHubApp::query()->where('user_id', $developer->id)->exists())->toBeFalse()
        ->and($application->fresh()->githubProblem())->toBe('removed');
});

test("an app on a member's own GitHub is never moved onto the team's connection, and the reverse", function () {
    ['team' => $team, 'own' => $own, 'team_connection' => $teamConnection] = ownGitHubSetup();
    $mine = Application::factory()->for($team)->create(['source_provider_id' => $own->id, 'repository_id' => '881']);
    $ours = Application::factory()->for($team)->create(['source_provider_id' => $teamConnection->id, 'repository_id' => '882']);
    $own->delete();
    $teamConnection->delete();
    $newTeam = SourceProvider::factory()->githubApp(GitHubApp::factory()->create(['team_id' => $team->id]))->create(['team_id' => $team->id, 'name' => 'lena-b']);
    $newOwn = SourceProvider::factory()->githubApp(GitHubApp::factory()->create(['team_id' => $team->id, 'user_id' => $own->user_id]))->create(['team_id' => $team->id, 'user_id' => $own->user_id, 'name' => 'monoventech']);
    $relinker = app(ApplicationRelinker::class);

    expect($relinker->stranded($newTeam)->pluck('id')->all())->not->toContain($mine->id)
        ->and($relinker->stranded($newOwn)->pluck('id')->all())->not->toContain($ours->id);
});
