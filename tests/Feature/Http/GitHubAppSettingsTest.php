<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\ManifestBuilder;
use App\Services\Network\AddressResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

function githubAppSettingsTeam(string $role = 'owner'): array
{
    $team = Team::factory()->create(['slug' => 'monoven']);
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role]);

    return [$team, $user->fresh()];
}

function githubAppSettingsApp(Team $team, array $attributes = []): GitHubApp
{
    return GitHubApp::factory()->for($team)->create(array_merge([
        'name' => 'Coresmith Monoven',
        'slug' => 'coresmith-monoven',
        'owner_login' => 'monoventech',
        'owner_type' => 'Organization',
    ], $attributes));
}

function githubAppSettingsConnection(Team $team, ?GitHubApp $app, array $attributes = []): SourceProvider
{
    return SourceProvider::factory()->for($team)->create(array_merge([
        'type' => 'github_app',
        'github_app_id' => $app?->id,
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
    ], $attributes));
}

function githubAppSettingsExpectedHook(GitHubApp $app): string
{
    return app(GitHubAppHealth::class)->expectedHookUrl(app(GitHubApps::class)->fromModel($app));
}

function githubAppSettingsEnv(): void
{
    config(['services.github' => [
        'app_id' => '1041207',
        'app_slug' => 'coresmith-cloud',
        'client_id' => 'Iv1.env',
        'client_secret' => 'env-secret',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----",
        'webhook_secret' => 'env-hook-secret',
    ]]);
}

beforeEach(function () {
    Http::preventStrayRequests();

    config(['inertia.ssr.enabled' => false]);

    config(['services.github' => [
        'app_id' => null,
        'app_slug' => 'coresmith',
        'client_id' => null,
        'client_secret' => null,
        'private_key' => null,
        'webhook_secret' => null,
    ]]);

    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['140.82.112.3'];
        }
    });
});

test('pointing the webhook here sets this panel\'s address and a new secret', function (?string $hook, ?string $secret) {
    Http::fake(['api.github.com/app/hook/config' => Http::response(['url' => 'set', 'content_type' => 'json', 'insecure_ssl' => '0'])]);
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team, ['hook_url' => $hook, 'webhook_secret' => $secret]);
    $expected = githubAppSettingsExpectedHook($app);

    $this->actingAs($owner)
        ->from(route('source-providers.index'))
        ->post(route('github-apps.webhook', $app))
        ->assertRedirect(route('source-providers.index'))
        ->assertSessionHas('success', 'Saved. On GitHub, check the app\'s webhook is active and sends push events.');

    $fresh = $app->fresh();

    expect($expected)->toBe("http://localhost/api/webhooks/github/{$app->id}")
        ->and($fresh->hook_url)->toBe($expected)
        ->and($fresh->webhook_secret)->toMatch('/^[0-9a-f]{64}\z/')
        ->and($fresh->webhook_secret)->not->toBe($secret)
        ->and(DB::table('github_apps')->where('id', $app->id)->value('webhook_secret'))->not->toContain($fresh->webhook_secret);

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'PATCH'
        && $request->url() === 'https://api.github.com/app/hook/config'
        && $request['url'] === $expected
        && $request['secret'] === $fresh->webhook_secret
        && $request['content_type'] === 'json'
        && $request['insecure_ssl'] === '0'
        && str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer '));
})->with([
    'an app without a webhook' => [null, null],
    'an app whose webhook points at an old address' => ['https://old.example.com/api/webhooks/github/01jaj4v0z8x6v3c2c2e8n1q6m7', 'old-webhook-secret'],
]);

test('a webhook github will not change says so and keeps the old settings', function (string $failure, string $error) {
    Http::fake(['api.github.com/app/hook/config' => match ($failure) {
        'a refused address' => Http::response(['message' => 'Validation Failed', 'documentation_url' => 'https://docs.github.com/rest/apps/webhooks#update-a-webhook-configuration-for-an-app'], 422),
        'a key github does not accept' => Http::response(['message' => 'A JSON web token could not be decoded'], 401),
        'no answer' => fn() => throw new ConnectionException('cURL error 28: Operation timed out'),
    }]);
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team, ['hook_url' => 'https://old.example.com/api/webhooks/github/x', 'webhook_secret' => 'old-webhook-secret']);
    $credentials = app(GitHubApps::class)->fromModel($app);
    $health = app(GitHubAppHealth::class);
    $health->checked($credentials, ['status_code' => 0, 'status' => 'timed out', 'delivered_at' => now()->subMinutes(10)]);

    $this->actingAs($owner)
        ->from(route('source-providers.index'))
        ->post(route('github-apps.webhook', $app))
        ->assertRedirect(route('source-providers.index'))
        ->assertSessionHas('error', $error)
        ->assertSessionMissing('success');

    $fresh = $app->fresh();

    expect($fresh->hook_url)->toBe('https://old.example.com/api/webhooks/github/x')
        ->and($fresh->webhook_secret)->toBe('old-webhook-secret')
        ->and(Cache::has($health->key($credentials)))->toBeTrue()
        ->and($error)->not->toContain('GitHub rejected the request');
})->with([
    'a refused address' => ['a refused address', 'GitHub did not change the webhook: Validation Failed. Try again in a minute.'],
    'a key github does not accept' => ['a key github does not accept', 'GitHub did not change the webhook: A JSON web token could not be decoded. Try again in a minute.'],
    'no answer' => ['no answer', 'GitHub did not change the webhook: GitHub did not answer. Try again in a minute.'],
]);

test('a repoint github accepts forgets the failed delivery it was reporting', function () {
    Http::fake(['api.github.com/app/hook/config' => Http::response(['url' => 'set', 'content_type' => 'json', 'insecure_ssl' => '0'])]);
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team, ['hook_url' => 'https://old.example.com/api/webhooks/github/x', 'webhook_secret' => 'old-webhook-secret']);
    githubAppSettingsConnection($team, $app);
    $credentials = app(GitHubApps::class)->fromModel($app);
    $health = app(GitHubAppHealth::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
    $health->checked($credentials, ['status_code' => 0, 'status' => 'timed out', 'delivered_at' => CarbonImmutable::parse('2026-10-18 13:55:00')]);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.webhook.0.instant', false)
            ->where('github.webhook.0.sentence', 'GitHub\'s last delivery here failed Oct 18, 2026 at 13:55 UTC: timed out.'));

    $this->actingAs($owner)
        ->from(route('source-providers.index'))
        ->post(route('github-apps.webhook', $app))
        ->assertSessionHas('success', 'Saved. On GitHub, check the app\'s webhook is active and sends push events.');

    expect(Cache::has($health->key($credentials)))->toBeFalse();

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.webhook', [[
            'app_name' => 'Coresmith Monoven',
            'instant' => false,
            'sentence' => 'No delivery from GitHub has reached this panel yet.',
            'sync_href' => null,
        ]]));
});

test('removing an app with no live installation forgets its secrets', function () {
    Http::fake();
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team, ['client_secret' => 'cs_remove_me', 'webhook_secret' => 'whsec_remove_me']);
    githubAppSettingsConnection($team, $app)->delete();
    $credentials = app(GitHubApps::class)->fromModel($app);
    $health = app(GitHubAppHealth::class);
    $health->received($credentials, 'push', 'delivery-1');

    expect(Cache::has($health->key($credentials)))->toBeTrue();

    $this->actingAs($owner)
        ->from(route('source-providers.index'))
        ->delete(route('github-apps.destroy', $app))
        ->assertRedirect(route('source-providers.index'))
        ->assertSessionHas('success', 'Removed. Delete the app on GitHub to finish.');

    $activity = Activity::query()->where('description', 'github_app_removed')->sole();

    expect(GitHubApp::query()->find($app->id))->toBeNull()
        ->and(DB::table('github_apps')->count())->toBe(0)
        ->and(Cache::has($health->key($credentials)))->toBeFalse()
        ->and($activity->causer_id)->toBe($owner->id)
        ->and($activity->subject_id)->toBe($app->id)
        ->and($activity->properties->toArray())->toBe(['app_id' => $app->app_id, 'slug' => 'coresmith-monoven', 'owner' => 'monoventech'])
        ->and(json_encode($activity->getAttributes()))->not->toContain('remove_me');

    Http::assertNothingSent();
});

test('an app still in use cannot be removed', function (array $connection) {
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team);
    githubAppSettingsConnection($team, $app, $connection);

    $this->actingAs($owner)
        ->from(route('source-providers.index'))
        ->delete(route('github-apps.destroy', $app))
        ->assertRedirect(route('source-providers.index'))
        ->assertSessionHas('error', 'Disconnect its installations first.')
        ->assertSessionMissing('success');

    expect(GitHubApp::query()->find($app->id))->not->toBeNull()
        ->and(Activity::query()->where('description', 'github_app_removed')->count())->toBe(0);
})->with([
    'a live installation' => [[]],
    'a suspended installation' => [['suspended_at' => now()]],
]);

test('another team\'s app is a not found', function (string $action) {
    Http::fake();
    [, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp(Team::factory()->create(['slug' => 'elsewhere']), ['hook_url' => null]);

    $request = $this->actingAs($owner);
    $response = $action === 'point' ? $request->post(route('github-apps.webhook', $app)) : $request->delete(route('github-apps.destroy', $app));

    $response->assertNotFound();

    expect(GitHubApp::query()->find($app->id)?->hook_url)->toBeNull();
    Http::assertNothingSent();
})->with(['point', 'remove']);

test('an app of another team the user belongs to is a not found under this team\'s address', function (string $action) {
    Http::fake();
    [$team, $owner] = githubAppSettingsTeam();
    $other = Team::factory()->create(['slug' => 'elsewhere']);
    $other->members()->attach($owner, ['role' => 'owner']);
    $app = githubAppSettingsApp($other, ['hook_url' => null]);
    $path = "/{$team->slug}/settings/git/github/apps/{$app->id}";

    $request = $this->actingAs($owner);
    $response = $action === 'point' ? $request->post("{$path}/webhook") : $request->delete($path);

    $response->assertNotFound();

    expect(GitHubApp::query()->find($app->id)?->hook_url)->toBeNull();
    Http::assertNothingSent();
})->with(['point', 'remove']);

test('a member cannot point or remove', function (string $action) {
    Http::fake();
    [$team, $member] = githubAppSettingsTeam('member');
    $app = githubAppSettingsApp($team, ['hook_url' => null]);

    $request = $this->actingAs($member);
    $response = $action === 'point' ? $request->post(route('github-apps.webhook', $app)) : $request->delete(route('github-apps.destroy', $app));

    $response->assertForbidden();

    expect(GitHubApp::query()->find($app->id)?->hook_url)->toBeNull();
    Http::assertNothingSent();
})->with(['point', 'remove']);

test('the git page explains each app\'s webhook', function () {
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team, ['hook_url' => null, 'webhook_secret' => null]);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->has('github.webhook', 1)
            ->where('github.webhook.0.sentence', 'GitHub cannot reach localhost, it only answers on this machine.')
            ->where('github.webhook.0', [
                'app_name' => 'Coresmith Monoven',
                'instant' => false,
                'sentence' => 'GitHub cannot reach localhost, it only answers on this machine.',
                'sync_href' => route('github-apps.webhook', $app),
            ]));
});

test('an app whose webhook already points here is not offered a repoint', function () {
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team);
    githubAppSettingsConnection($team, $app);

    expect($app->hook_url)->toBe(githubAppSettingsExpectedHook($app));

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.webhook', [[
            'app_name' => 'Coresmith Monoven',
            'instant' => false,
            'sentence' => 'No delivery from GitHub has reached this panel yet.',
            'sync_href' => null,
        ]]));
});

test('a panel that moved is told to point the webhook here', function () {
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team, ['hook_url' => 'https://old.example.com/api/webhooks/github/x']);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.webhook.0.sentence', 'This panel\'s address changed after the GitHub app was created. Point its webhook here.')
            ->where('github.webhook.0.sync_href', route('github-apps.webhook', $app)));
});

test('an app github reached says pushes arrive as they happen', function () {
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team);
    githubAppSettingsConnection($team, $app);

    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:02:00'));
    app(GitHubAppHealth::class)->received(app(GitHubApps::class)->fromModel($app), 'push', 'delivery-1');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.webhook.0.instant', true)
            ->where('github.webhook.0.sentence', 'GitHub reached this panel 3 minutes ago.'));
});

test('a member reads the webhook sentence but is not offered a repoint', function () {
    [$team, $member] = githubAppSettingsTeam('member');
    githubAppSettingsApp($team, ['hook_url' => null, 'webhook_secret' => null]);

    $this->actingAs($member)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.webhook.0.sentence', 'GitHub cannot reach localhost, it only answers on this machine.')
            ->where('github.webhook.0.sync_href', null));
});

test('the environment app\'s webhook is explained once the team uses it and is never repointed', function () {
    githubAppSettingsEnv();
    [$team, $owner] = githubAppSettingsTeam();

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.webhook', []));

    githubAppSettingsConnection($team, null);
    githubAppSettingsConnection($team, null, ['name' => 'monoven-labs', 'installation_id' => '51843']);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.webhook', [[
            'app_name' => 'Coresmith',
            'instant' => false,
            'sentence' => 'No delivery from GitHub has reached this panel yet.',
            'sync_href' => null,
        ]]));
});

test('a connection whose app is gone explains no webhook', function () {
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team);
    githubAppSettingsConnection($team, $app);
    $app->delete();

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->has('github.connections', 1)->where('github.webhook', []));
});

test('an app can be removed from the page only while nothing is connected through it', function (string $role, bool $connected, bool $offered) {
    [$team, $user] = githubAppSettingsTeam($role);
    $app = githubAppSettingsApp($team);

    if ($connected) {
        githubAppSettingsConnection($team, $app);
    }

    $this->actingAs($user)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.apps.0.remove_href', $offered ? route('github-apps.destroy', $app) : null));
})->with([
    'an owner, nothing connected' => ['owner', false, true],
    'an admin, nothing connected' => ['admin', false, true],
    'an owner, an installation connected' => ['owner', true, false],
    'a member, nothing connected' => ['member', false, false],
]);

test('the last delivery is the newest of the team\'s connections and its app endpoints', function (string $newest) {
    [$team, $owner] = githubAppSettingsTeam();
    $app = githubAppSettingsApp($team);
    githubAppSettingsConnection($team, $app, [
        'last_delivery_id' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        'last_event' => 'push',
        'last_status' => 202,
        'last_delivered_at' => $newest === 'connection' ? now()->subMinutes(2) : now()->subHour(),
    ]);
    githubAppSettingsConnection(Team::factory()->create(), null, [
        'name' => 'someone-else',
        'last_delivery_id' => 'ffffffff-0000-0000-0000-000000000000',
        'last_event' => 'push',
        'last_status' => 202,
        'last_delivered_at' => now(),
    ]);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();
    WebhookEndpoint::factory()->for($application)->create([
        'last_delivery_id' => 'abcdef0123456789',
        'last_event' => 'ping',
        'last_status' => 200,
        'last_delivered_at' => $newest === 'endpoint' ? now()->subMinutes(2) : now()->subHour(),
    ]);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('webhooks.last', $newest === 'connection'
            ? ['id' => '72d3162e', 'event' => 'push', 'status' => 202, 'verified' => true, 'when' => '2 minutes ago']
            : ['id' => 'abcdef01', 'event' => 'ping', 'status' => 200, 'verified' => true, 'when' => '2 minutes ago']));
})->with(['connection', 'endpoint']);

test('a disconnected installation\'s last delivery is not shown', function () {
    [$team, $owner] = githubAppSettingsTeam();
    githubAppSettingsConnection($team, githubAppSettingsApp($team), [
        'last_delivery_id' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        'last_event' => 'push',
        'last_status' => 202,
        'last_delivered_at' => now()->subMinutes(2),
    ])->delete();

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('webhooks.last', null));
});

test('pointing the webhook and removing an app are routes of their own', function () {
    $webhook = Route::getRoutes()->getByName('github-apps.webhook');
    $destroy = Route::getRoutes()->getByName('github-apps.destroy');

    expect($webhook->methods())->toBe(['POST'])
        ->and($webhook->uri())->toBe('settings/git/github/apps/{githubApp}/webhook')
        ->and($webhook->wheres)->toHaveKey('githubApp')
        ->and($webhook->gatherMiddleware())->toContain('throttle:6,1,github-app-webhook-sync')
        ->and($webhook->gatherMiddleware())->toContain('auth')
        ->and($destroy->methods())->toBe(['DELETE'])
        ->and($destroy->uri())->toBe('settings/git/github/apps/{githubApp}')
        ->and($destroy->wheres)->toHaveKey('githubApp')
        ->and($destroy->gatherMiddleware())->toContain('auth');
});

test('an address that is not an app is a not found', function (string $key) {
    Http::fake();
    [, $owner] = githubAppSettingsTeam();

    $this->actingAs($owner)->post("/settings/git/github/apps/{$key}/webhook")->assertNotFound();
    $this->actingAs($owner)->delete("/settings/git/github/apps/{$key}")->assertNotFound();

    Http::assertNothingSent();
})->with(['not-an-app', '01jaj4v0z8x6v3c2c2e8n1q6m7']);

test('the browser checks names with the panel\'s own patterns', function () {
    $source = (string) file_get_contents(resource_path('js/lib/github.ts'));

    preg_match('~export const ORGANIZATION_PATTERN = (/.+/)\n~', $source, $organization);
    preg_match('~export const APP_NAME_PATTERN = (/.+/)\n~', $source, $name);

    expect($organization[1] ?? null)->toBe(str_replace('\z/', '$/', ManifestBuilder::ORGANIZATION_PATTERN))
        ->and($name[1] ?? null)->toBe(str_replace('\z/', '$/', ManifestBuilder::NAME_PATTERN))
        ->and($source)->not->toContain('\z');
});
