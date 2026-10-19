<?php

declare(strict_types=1);

use App\Exceptions\GitProviderException;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\AppJwt;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubApps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use phpseclib3\Crypt\PublicKeyLoader;
use Spatie\Activitylog\Models\Activity;

function githubAppsPem(): string
{
    static $pem = null;

    if ($pem === null) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    }

    return $pem;
}

function githubAppsConfigureEnv(array $overrides = []): void
{
    config(['services.github' => array_merge([
        'app_id' => '1041207',
        'app_slug' => 'coresmith',
        'client_id' => 'Iv1.0123456789abcdef',
        'client_secret' => 'env-client-secret',
        'private_key' => githubAppsPem(),
        'webhook_secret' => 'env-webhook-secret',
    ], $overrides)]);
}

function githubAppsKeys(array $apps): array
{
    return array_map(fn(GitHubAppCredentials $app): string => $app->key, $apps);
}

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.github' => [
        'app_id' => null,
        'app_slug' => 'coresmith',
        'client_id' => null,
        'client_secret' => null,
        'private_key' => null,
        'webhook_secret' => null,
    ]]);
});

test('the environment app is offered only when every credential is set', function (string $missing, ?string $value) {
    githubAppsConfigureEnv([$missing => $value]);

    expect(app(GitHubApps::class)->env())->toBeNull();
})->with(function () {
    foreach (['app_id', 'client_id', 'client_secret', 'private_key'] as $key) {
        yield "{$key} unset" => [$key, null];
        yield "{$key} blank" => [$key, '   '];
    }
});

test('the environment app carries the credentials it was configured with', function () {
    githubAppsConfigureEnv(['app_slug' => 'coresmith-cloud']);

    $env = app(GitHubApps::class)->env();

    expect($env)->toBeInstanceOf(GitHubAppCredentials::class)
        ->and($env->key)->toBe('env')
        ->and($env->isEnv())->toBeTrue()
        ->and($env->appId)->toBe('1041207')
        ->and($env->slug)->toBe('coresmith-cloud')
        ->and($env->name)->toBe('Coresmith')
        ->and($env->clientId)->toBe('Iv1.0123456789abcdef')
        ->and($env->clientSecret)->toBe('env-client-secret')
        ->and($env->webhookSecret)->toBe('env-webhook-secret')
        ->and($env->htmlUrl)->toBe('https://github.com/apps/coresmith-cloud')
        ->and($env->ownerLogin)->toBeNull()
        ->and($env->ownerType)->toBeNull()
        ->and($env->hookUrl)->toBeNull();
});

test('the environment app tolerates a missing webhook secret and a blank slug', function () {
    githubAppsConfigureEnv(['webhook_secret' => '', 'app_slug' => '']);

    $env = app(GitHubApps::class)->env();

    expect($env->webhookSecret)->toBeNull()
        ->and($env->slug)->toBe('coresmith')
        ->and($env->htmlUrl)->toBe('https://github.com/apps/coresmith');
});

test('a private key written with escaped newlines or as base64 is read back as a pem', function (string $form) {
    $pem = githubAppsPem();

    githubAppsConfigureEnv(['private_key' => match ($form) {
        'escaped newlines' => str_replace("\n", '\n', $pem),
        'base64' => base64_encode($pem),
        'padded base64' => "  \n" . base64_encode($pem) . "\n",
    }]);

    expect(app(GitHubApps::class)->env()->privateKey)->toBe($pem);
})->with(['escaped newlines', 'base64', 'padded base64']);

test('a private key that is neither a pem nor base64 leaves the environment app unconfigured', function () {
    githubAppsConfigureEnv(['private_key' => 'this is not a key!']);

    expect(app(GitHubApps::class)->env())->toBeNull();
});

test('an unreadable environment key keeps the app configured and fails when it signs', function (string $form) {
    githubAppsConfigureEnv(['private_key' => match ($form) {
        'hex that is valid base64' => bin2hex(random_bytes(20)),
        'plain text' => 'abcd',
        'a truncated pem' => substr(githubAppsPem(), 0, 300),
    }]);
    $apps = app(GitHubApps::class);

    expect($apps->env())->not->toBeNull()
        ->and($apps->canRegister())->toBeFalse();

    expect(fn() => app(AppJwt::class)->for($apps->env()))->toThrow(GitProviderException::class, 'could not be read');
})->with(['hex that is valid base64', 'plain text', 'a truncated pem']);

test('a team is offered the environment app alone when one is configured', function () {
    githubAppsConfigureEnv();
    $team = Team::factory()->create();
    GitHubApp::factory()->for($team)->create();

    expect(githubAppsKeys(app(GitHubApps::class)->available($team)))->toBe(['env']);
});

test('otherwise each team is offered only its own registered apps', function () {
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $firstApp = GitHubApp::factory()->for($first)->create();
    $secondApp = GitHubApp::factory()->for($second)->create();
    $apps = app(GitHubApps::class);

    expect(githubAppsKeys($apps->available($first)))->toBe([$firstApp->id])
        ->and(githubAppsKeys($apps->available($second)))->toBe([$secondApp->id])
        ->and($apps->available(Team::factory()->create()))->toBe([]);
});

test('registered apps are offered newest first', function () {
    $team = Team::factory()->create();
    $older = GitHubApp::factory()->for($team)->create(['created_at' => now()->subDay()]);
    $newest = GitHubApp::factory()->for($team)->create(['created_at' => now()]);
    $oldest = GitHubApp::factory()->for($team)->create(['created_at' => now()->subWeek()]);

    expect(githubAppsKeys(app(GitHubApps::class)->available($team)))->toBe([$newest->id, $older->id, $oldest->id]);
});

test('registering is offered only when no environment app is configured', function () {
    $apps = app(GitHubApps::class);

    expect($apps->canRegister())->toBeTrue();

    githubAppsConfigureEnv();

    expect($apps->canRegister())->toBeFalse();
});

test('a connection resolves the app it was made through', function () {
    $apps = app(GitHubApps::class);
    $app = GitHubApp::factory()->create();
    $registered = SourceProvider::factory()->githubApp($app)->create();

    expect($apps->for($registered)?->key)->toBe($app->id)
        ->and($apps->for(SourceProvider::factory()->create(['github_app_id' => null])))->toBeNull();

    githubAppsConfigureEnv();

    expect($apps->for(SourceProvider::factory()->create(['github_app_id' => null]))?->key)->toBe('env')
        ->and($apps->for($registered)?->key)->toBe($app->id);

    $app->delete();

    expect($apps->for($registered->fresh()))->toBeNull();
});

test('a key resolves to the environment app, a registered app or nothing', function () {
    $apps = app(GitHubApps::class);
    $app = GitHubApp::factory()->create();

    expect($apps->forKey('env'))->toBeNull()
        ->and($apps->forKey($app->id)?->appId)->toBe($app->app_id)
        ->and($apps->forKey('01jzzzzzzzzzzzzzzzzzzzzzzz'))->toBeNull()
        ->and($apps->forKey(''))->toBeNull();

    githubAppsConfigureEnv();

    expect($apps->forKey('env')?->isEnv())->toBeTrue();
});

test('a registered app becomes credentials carrying everything it stores', function () {
    $pem = githubAppsPem();
    $app = GitHubApp::factory()->create([
        'app_id' => '2718281',
        'slug' => 'coresmith-forge',
        'name' => 'Coresmith Forge',
        'client_id' => 'Iv1.forge',
        'client_secret' => 'forge-client-secret',
        'webhook_secret' => 'forge-webhook-secret',
        'private_key' => $pem,
        'owner_login' => 'monoventech',
        'owner_type' => 'Organization',
        'html_url' => 'https://github.com/apps/coresmith-forge',
        'hook_url' => 'https://panel.example.com/api/webhooks/github/forge',
    ]);

    expect(app(GitHubApps::class)->fromModel($app))->toEqual(new GitHubAppCredentials(
        key: $app->id,
        appId: '2718281',
        slug: 'coresmith-forge',
        name: 'Coresmith Forge',
        clientId: 'Iv1.forge',
        clientSecret: 'forge-client-secret',
        privateKey: $pem,
        webhookSecret: 'forge-webhook-secret',
        ownerLogin: 'monoventech',
        ownerType: 'Organization',
        htmlUrl: 'https://github.com/apps/coresmith-forge',
        hookUrl: 'https://panel.example.com/api/webhooks/github/forge',
    ))->and(app(GitHubApps::class)->fromModel($app)->isEnv())->toBeFalse();
});

test('app urls point at the app on github', function () {
    $apps = app(GitHubApps::class);
    $organization = $apps->fromModel(GitHubApp::factory()->create(['slug' => 'coresmith-forge', 'owner_login' => 'monoventech', 'owner_type' => 'Organization']));
    $user = $apps->fromModel(GitHubApp::factory()->create(['slug' => 'coresmith-home', 'owner_login' => 'oseughu', 'owner_type' => 'User']));
    githubAppsConfigureEnv();
    $env = $apps->env();

    expect($organization->installUrl('state-abc123'))->toBe('https://github.com/apps/coresmith-forge/installations/new?state=state-abc123')
        ->and($user->installUrl('xyz'))->toBe('https://github.com/apps/coresmith-home/installations/new?state=xyz')
        ->and($env->installUrl('xyz'))->toBe('https://github.com/apps/coresmith/installations/new?state=xyz')
        ->and($organization->settingsUrl())->toBe('https://github.com/organizations/monoventech/settings/apps/coresmith-forge')
        ->and($user->settingsUrl())->toBe('https://github.com/settings/apps/coresmith-home')
        ->and($env->settingsUrl())->toBeNull();
});

test('app secrets are encrypted at rest and never serialised', function () {
    $app = GitHubApp::factory()->create(['client_secret' => 'plain-client-secret', 'webhook_secret' => 'plain-webhook-secret']);

    $row = implode('|', array_map('strval', (array) DB::table('github_apps')->where('id', $app->id)->first()));
    $serialised = $app->fresh()->toArray();

    expect($row)->not->toContain('plain-client-secret')
        ->and($row)->not->toContain('plain-webhook-secret')
        ->and($row)->not->toContain('PRIVATE KEY')
        ->and($app->fresh()->client_secret)->toBe('plain-client-secret')
        ->and($app->fresh()->webhook_secret)->toBe('plain-webhook-secret')
        ->and($app->fresh()->private_key)->toContain('PRIVATE KEY')
        ->and($serialised)->not->toHaveKey('client_secret')
        ->and($serialised)->not->toHaveKey('webhook_secret')
        ->and($serialised)->not->toHaveKey('private_key')
        ->and(json_encode($serialised))->not->toContain('plain-client-secret');
});

test('permissions and events round trip as arrays', function () {
    $app = GitHubApp::factory()->create([
        'permissions' => ['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'],
        'events' => ['push'],
    ]);

    expect($app->fresh()->permissions)->toBe(['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'])
        ->and($app->fresh()->events)->toBe(['push']);
});

test('an app knows its team and the live connections made through it', function () {
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();
    $live = SourceProvider::factory()->for($team)->githubApp($app)->create();
    $gone = SourceProvider::factory()->for($team)->githubApp($app)->create();
    $gone->delete();
    SourceProvider::factory()->for($team)->create(['github_app_id' => null]);

    expect($app->team->is($team))->toBeTrue()
        ->and($team->githubApps->pluck('id')->all())->toBe([$app->id])
        ->and($app->connections->pluck('id')->all())->toBe([$live->id])
        ->and($live->githubApp->is($app))->toBeTrue();
});

test('an app does not outlive its team', function () {
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();

    $team->forceDelete();

    expect(GitHubApp::find($app->id))->toBeNull();
});

test('an app outlives the person who registered it', function () {
    $person = User::factory()->create();
    $app = GitHubApp::factory()->create(['created_by' => $person->id]);

    $person->delete();

    expect(GitHubApp::find($app->id))->not->toBeNull()
        ->and(GitHubApp::find($app->id)->created_by)->toBeNull();
});

test('the app factory signs with a real key and can leave the webhook out', function () {
    $app = GitHubApp::factory()->create();
    $bare = GitHubApp::factory()->withoutWebhook()->create();

    expect(openssl_pkey_get_private($app->private_key))->not->toBeFalse()
        ->and($app->webhook_secret)->toMatch('/^[0-9a-f]{40}$/')
        ->and($app->app_id)->toMatch('/^\d{7}$/')
        ->and($app->slug)->toStartWith('coresmith-')
        ->and($app->owner_type)->toBe('Organization')
        ->and($bare->webhook_secret)->toBeNull()
        ->and($bare->hook_url)->toBeNull()
        ->and(GitHubApp::factory()->create()->app_id)->not->toBe($app->app_id);
});

test('the connection factory links the app it is given or builds one for the same team', function () {
    $team = Team::factory()->create();
    $given = GitHubApp::factory()->for($team)->create();

    $linked = SourceProvider::factory()->for($team)->githubApp($given)->create();
    $built = SourceProvider::factory()->for($team)->githubApp()->create();
    $standalone = SourceProvider::factory()->githubApp()->create();

    expect($linked->type->value)->toBe('github_app')
        ->and($linked->github_app_id)->toBe($given->id)
        ->and($built->githubApp)->not->toBeNull()
        ->and($built->githubApp->is($given))->toBeFalse()
        ->and($built->githubApp->team_id)->toBe($team->id)
        ->and($standalone->githubApp->team_id)->toBe($standalone->team_id);
});

test('the jwt is signed with the app key for its id', function (string $format) {
    $pem = $format === 'PKCS1' ? PublicKeyLoader::load(githubAppsPem())->toString('PKCS1') : githubAppsPem();
    $app = GitHubApp::factory()->create(['private_key' => $pem]);
    $credentials = app(GitHubApps::class)->fromModel($app);
    $decode = fn(string $part): string => (string) base64_decode(strtr($part, '-_', '+/'));

    [$header, $claims, $signature] = explode('.', app(AppJwt::class)->for($credentials));
    $payload = json_decode($decode($claims), true, 512, JSON_THROW_ON_ERROR);

    expect($pem)->toContain($format === 'PKCS1' ? 'BEGIN RSA PRIVATE KEY' : 'BEGIN PRIVATE KEY')
        ->and(json_decode($decode($header), true, 512, JSON_THROW_ON_ERROR))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and($payload['iss'])->toBe($app->app_id)
        ->and($payload['iat'])->toBeLessThanOrEqual(time() - 59)
        ->and($payload['exp'] - $payload['iat'])->toBe(600)
        ->and($payload['exp'])->toBeGreaterThan(time())
        ->and(openssl_verify(
            $header . '.' . $claims,
            $decode($signature),
            openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'],
            OPENSSL_ALGO_SHA256,
        ))->toBe(1);
})->with(['PKCS8', 'PKCS1']);

test('a jwt cannot be signed without a readable app key and an id', function (string $case) {
    $pem = githubAppsPem();
    $missing = 'This GitHub connection is missing its App credentials.';
    $unreadable = 'This GitHub connection\'s App private key could not be read. Check the App credentials.';

    [$appId, $privateKey, $message] = match ($case) {
        'no key' => ['1041207', '', $missing],
        'no id' => ['', $pem, $missing],
        'junk bytes from valid base64' => ['1041207', (string) base64_decode(bin2hex(random_bytes(20)), true), $unreadable],
        'plain text' => ['1041207', 'abcd', $unreadable],
        'a truncated pem' => ['1041207', substr($pem, 0, 300), $unreadable],
        'a pem with no body' => ['1041207', "-----BEGIN PRIVATE KEY-----\n-----END PRIVATE KEY-----", $unreadable],
        'a public key' => ['1041207', PublicKeyLoader::load($pem)->getPublicKey()->toString('PKCS8'), $unreadable],
    };
    $credentials = new GitHubAppCredentials('env', $appId, 'coresmith', 'Coresmith', 'client', 'secret', $privateKey, null, null, null, null, null);

    expect(fn() => app(AppJwt::class)->for($credentials))->toThrow(function (GitProviderException $exception) use ($message) {
        expect($exception->getMessage())->toBe($message)
            ->and($exception->getPrevious())->toBeNull();
    });
})->with(['no key', 'no id', 'junk bytes from valid base64', 'plain text', 'a truncated pem', 'a pem with no body', 'a public key']);

test('a github connection is connected while its installation is linked and not suspended', function () {
    $trashed = SourceProvider::factory()->create();
    $trashed->delete();

    expect(SourceProvider::factory()->create()->connected())->toBeTrue()
        ->and(SourceProvider::factory()->create(['installation_id' => null])->connected())->toBeFalse()
        ->and(SourceProvider::factory()->create(['suspended_at' => now()])->connected())->toBeFalse()
        ->and($trashed->connected())->toBeFalse()
        ->and(SourceProvider::factory()->create(['access_token' => null])->connected())->toBeTrue()
        ->and(SourceProvider::factory()->create(['type' => 'gitlab', 'access_token' => null])->connected())->toBeFalse()
        ->and(SourceProvider::factory()->create(['type' => 'gitlab', 'access_token' => 'glpat'])->connected())->toBeTrue()
        ->and(SourceProvider::factory()->create(['type' => 'bitbucket', 'access_token' => null])->connected())->toBeFalse()
        ->and(SourceProvider::factory()->create(['type' => 'custom', 'access_token' => null])->connected())->toBeTrue();
});

test('a suspended installation stays linked but is not connected until it is unsuspended', function () {
    $provider = SourceProvider::factory()->create();

    $provider->markSuspended(now());

    expect($provider->fresh()->suspended_at)->not->toBeNull()
        ->and($provider->fresh()->connected())->toBeFalse()
        ->and($provider->fresh()->installation_id)->toBe($provider->installation_id)
        ->and($provider->fresh()->trashed())->toBeFalse();

    $provider->markSuspended(null);

    expect($provider->fresh()->suspended_at)->toBeNull()
        ->and($provider->fresh()->connected())->toBeTrue();
});

test('manage url opens the installation on the account that installed it', function () {
    $organization = SourceProvider::factory()->create(['name' => 'monoventech', 'installation_id' => '51842', 'account_type' => 'Organization']);
    $user = SourceProvider::factory()->create(['name' => 'oseughu', 'installation_id' => '51842', 'account_type' => 'User']);

    expect($organization->manageUrl())->toBe('https://github.com/organizations/monoventech/settings/installations/51842')
        ->and($user->manageUrl())->toBe('https://github.com/settings/installations/51842')
        ->and(SourceProvider::factory()->create(['installation_id' => '51842', 'account_type' => null])->manageUrl())->toBeNull()
        ->and(SourceProvider::factory()->create(['installation_id' => null, 'account_type' => 'User'])->manageUrl())->toBeNull();
});

test('an uninstalled installation forgets its token and leaves', function () {
    $provider = SourceProvider::factory()->create(['access_token' => 'ghs_to_forget', 'refresh_token' => 'rt_to_forget']);

    $provider->markUninstalled();

    $row = DB::table('source_providers')->where('id', $provider->id)->first();
    $activity = Activity::query()->where('description', 'github_installation_removed')->sole();

    expect($row->access_token)->toBeNull()
        ->and($row->refresh_token)->toBeNull()
        ->and($row->token_expires_at)->toBeNull()
        ->and($row->deleted_at)->not->toBeNull()
        ->and(SourceProvider::find($provider->id))->toBeNull()
        ->and(SourceProvider::withTrashed()->find($provider->id)->access_token)->toBeNull()
        ->and($activity->subject_id)->toBe($provider->id)
        ->and($activity->subject_type)->toBe($provider->getMorphClass())
        ->and(json_encode($activity->properties))->not->toContain('ghs_to_forget')
        ->and(json_encode($activity->properties))->not->toContain('rt_to_forget');
});

test('the link migration reverses cleanly and puts the dropped columns back as nullable', function () {
    $migration = require base_path('database/migrations/2026_10_18_200100_link_github_apps_to_source_providers.php');
    $added = ['github_app_id', 'account_type', 'repository_selection', 'suspended_at', 'last_delivery_id', 'last_event', 'last_status', 'last_delivered_at'];

    $migration->down();

    $restored = collect(Schema::getColumns('source_providers'))->keyBy('name');

    expect($restored->only(['app_id', 'app_private_key'])->map(fn(array $column): bool => $column['nullable'])->all())
        ->toEqual(['app_id' => true, 'app_private_key' => true])
        ->and(array_filter($added, fn(string $column): bool => Schema::hasColumn('source_providers', $column)))->toBe([]);

    $migration->up();

    expect(Schema::hasColumns('source_providers', $added))->toBeTrue()
        ->and(Schema::hasColumn('source_providers', 'app_id'))->toBeFalse()
        ->and(Schema::hasColumn('source_providers', 'app_private_key'))->toBeFalse();
});
