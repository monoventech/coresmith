<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\DeploymentAborted;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Http::preventStrayRequests();
});

function privateModulesApp(): Application
{
    $team = Team::factory()->create();
    $provider = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
    ]);

    return Application::factory()->for($team)->create([
        'source_provider_id' => $provider->id,
        'slug' => 'my-app',
        'domain' => null,
        'type' => ApplicationType::Static,
        'framework' => null,
        'web_directory' => '/',
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_id' => '70000001',
        'repository_branch' => 'main',
        'base_port' => 8000,
    ]);
}

function privateModulesFake(): void
{
    Http::fake([
        'api.github.com/app/installations/51842/access_tokens' => Http::sequence()
            ->push(['token' => 'ghs_main_token', 'expires_at' => '2026-11-16T13:00:00Z'], 201)
            ->push(['token' => 'ghs_modules_token', 'expires_at' => '2026-11-16T13:00:00Z'], 201),
        'api.github.com/app/installations/51842' => Http::response(['id' => 51842, 'account' => ['login' => 'monoventech', 'type' => 'Organization'], 'suspended_at' => null]),
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
            ['id' => 70000001, 'full_name' => 'monoventech/atlas', 'private' => true, 'default_branch' => 'main'],
            ['id' => 70000002, 'full_name' => 'MonovenTech/Design-System', 'private' => true, 'default_branch' => 'main'],
        ]]),
        'api.github.com/installation/token' => Http::response(null, 204),
        'api.github.com/repos/*/statuses/*' => Http::response(['id' => 1], 201),
    ]);
}

function privateModulesRun(Deployment $deployment, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261116100000'))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function privateModulesTransport(string $gitmodules, string $tree): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n")->respondTo('/.gitmodules', $gitmodules)->respondTo(' ls-tree ', $tree);
}

function privateModulesClone(FakeSSHTransport $transport, string $url): string
{
    return array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'clone --bare') && str_contains($command, "'{$url}'")))[0] ?? '';
}

test('a private submodule the GitHub app can read is fetched with a one-hour token for it alone, and a public one without', function () {
    privateModulesFake();
    $transport = privateModulesTransport(
        "submodule.design.path=design\nsubmodule.design.url=../design-system.git\nsubmodule.realworld.path=realworld\nsubmodule.realworld.url=https://github.com/realworld-apps/realworld.git\n",
        "160000 commit 0123456789abcdef0123456789abcdef01234567\tdesign\n160000 commit 25588846c314b15a5e427ffe816e6b4af7be68c7\trealworld\n",
    );

    privateModulesRun(Deployment::factory()->for(privateModulesApp())->create(), $transport);

    $commands = $transport->commands();
    $design = privateModulesClone($transport, 'https://github.com/monoventech/design-system.git');
    $archive = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'archive 0123456789abcdef0123456789abcdef01234567')));
    $removed = array_keys(array_filter($commands, fn(string $command): bool => $command === "rm -f '/etc/coresmith/deploy-keys/my-app.git-token'"));

    expect($design)->toContain("-c 'credential.helper=!f() { test \"\$1\" = get && grep -qx host=github.com")
        ->and($design)->toContain('-c protocol.allow=never -c protocol.https.allow=always')
        ->and(privateModulesClone($transport, 'https://github.com/realworld-apps/realworld.git'))->not->toContain('credential.helper=!f()')
        ->and($transport->uploads()['/etc/coresmith/deploy-keys/my-app.git-token'])->toBe("username=x-access-token\npassword=ghs_modules_token\n")
        ->and(end($removed))->toBeGreaterThan($archive)
        ->and(implode("\n", $commands))->not->toContain('ghs_');

    Http::assertSent(fn($request) => str_ends_with($request->url(), '/app/installations/51842/access_tokens') && $request['repository_ids'] === [70000002] && $request['permissions'] === ['contents' => 'read']);
});

test('submodules outside the GitHub app\'s reach ask for no token', function () {
    privateModulesFake();
    $transport = privateModulesTransport(
        "submodule.realworld.path=realworld\nsubmodule.realworld.url=https://github.com/realworld-apps/realworld.git\n",
        "160000 commit 25588846c314b15a5e427ffe816e6b4af7be68c7\trealworld\n",
    );

    privateModulesRun(Deployment::factory()->for(privateModulesApp())->create(), $transport);

    Http::assertSent(fn($request) => str_contains($request->url(), '/installation/repositories'));
    Http::assertSent(fn($request) => str_ends_with($request->url(), '/access_tokens') && $request['repository_ids'] === [70000001]);
    Http::assertNotSent(fn($request) => str_ends_with($request->url(), '/access_tokens') && ($request['repository_ids'] ?? null) !== [70000001]);
});

test('a GitHub submodule the app cannot read says to give Coresmith\'s GitHub app access to it', function () {
    privateModulesFake();
    $transport = privateModulesTransport(
        "submodule.secret.path=secret\nsubmodule.secret.url=https://github.com/elsewhere/secret.git\n",
        "160000 commit 25588846c314b15a5e427ffe816e6b4af7be68c7\tsecret\n",
    )->respondTo('.modules/', "fatal: could not read Username for 'https://github.com': terminal prompts disabled", 128);
    $deployment = Deployment::factory()->for(privateModulesApp())->create();

    expect(fn() => privateModulesRun($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->failure_reason)->toBe("Coresmith couldn't read the submodule at secret from https://github.com/elsewhere/secret.git. Give Coresmith's GitHub app access to elsewhere/secret, or make it public.");
});
