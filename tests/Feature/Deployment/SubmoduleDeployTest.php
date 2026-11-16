<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\DeploymentAborted;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\Submodules;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

const SUBMODULE_RELEASE = '20261116-090000';

const SUBMODULE_PIN = '25588846c314b15a5e427ffe816e6b4af7be68c7';

const SUBMODULE_REPO = '/var/coresmith/apps/shop/repo';

const SUBMODULE_RELEASE_PATH = '/var/coresmith/apps/shop/releases/' . SUBMODULE_RELEASE;

function submoduleApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'type' => ApplicationType::Static,
        'framework' => null,
        'directory' => '/',
        'web_directory' => '/',
        'repository_url' => 'https://github.com/acme/shop.git',
        'repository_branch' => 'main',
        'run_migrations' => false,
        'base_port' => 8000,
        ...$attributes,
    ]);
}

function submoduleTransport(string $gitmodules, string $tree = ''): FakeSSHTransport
{
    return (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo('/.gitmodules', $gitmodules)
        ->respondTo(' ls-tree ', $tree);
}

function submoduleDeploy(Deployment $deployment, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, SUBMODULE_RELEASE))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function submoduleCommands(FakeSSHTransport $transport, string $needle): array
{
    return array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, $needle)));
}

function submoduleClone(FakeSSHTransport $transport): string
{
    return array_values(array_filter(submoduleCommands($transport, '.modules/'), fn(string $command): bool => str_contains($command, 'clone --bare')))[0] ?? '';
}

function realworldModules(string $url = 'https://github.com/realworld-apps/realworld.git'): string
{
    return "submodule.realworld.path=realworld\nsubmodule.realworld.url={$url}\n";
}

function realworldTree(): string
{
    return '160000 commit ' . SUBMODULE_PIN . "\trealworld\n";
}

test('a release whose repository has no submodules fetches none', function () {
    $transport = submoduleTransport('');

    submoduleDeploy(Deployment::factory()->for(submoduleApp())->create(), $transport);

    expect(submoduleCommands($transport, '.modules/'))->toBe([])
        ->and(submoduleCommands($transport, ' ls-tree '))->toBe([]);
});

test('each submodule is fetched at the commit the release pins, into its folder, before the release is handed to the app', function () {
    $transport = submoduleTransport(realworldModules(), realworldTree());
    $deployment = Deployment::factory()->for(submoduleApp())->create();

    submoduleDeploy($deployment, $transport);

    $cache = Submodules::cache(SUBMODULE_REPO, 'https://github.com/realworld-apps/realworld.git');
    $commands = $transport->commands();
    $clone = submoduleClone($transport);
    $fetch = submoduleCommands($transport, "--git-dir={$cache} fetch")[0] ?? '';
    $archive = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'archive ' . SUBMODULE_PIN)));
    $claim = array_search((new AppAccount)->claimRelease($deployment->application, SUBMODULE_RELEASE_PATH), $commands, true);

    expect($clone)->toContain("'https://github.com/realworld-apps/realworld.git' {$cache}")
        ->and($clone)->toContain('-c protocol.allow=never -c protocol.https.allow=always')
        ->and($clone)->toContain('GIT_TERMINAL_PROMPT=0')
        ->and($fetch)->toContain('cat-file -e ' . SUBMODULE_PIN)
        ->and($fetch)->toContain('fetch --quiet origin ' . SUBMODULE_PIN)
        ->and($commands[$archive])->toContain("tar -x -C '" . SUBMODULE_RELEASE_PATH . "/realworld'")
        ->and($archive)->toBeLessThan($claim);
});

test('the deploy log names each submodule, the commit it is pinned to and where it came from', function () {
    $deployment = Deployment::factory()->for(submoduleApp())->create();

    submoduleDeploy($deployment, submoduleTransport(realworldModules(), realworldTree()));

    expect($deployment->fresh()->log)->toContain('submodules: realworld at 2558884 from github.com/realworld-apps/realworld');
});

test('a relative submodule url is fetched from beside the app\'s repository, even when the app clones over SSH', function () {
    $transport = submoduleTransport(realworldModules('../theme.git'), realworldTree());

    submoduleDeploy(Deployment::factory()->for(submoduleApp(['repository_url' => 'git@github.com:acme/shop.git']))->create(), $transport);

    expect(submoduleClone($transport))->toContain("'https://github.com/acme/theme.git'");
});

test('a submodule that points at the server\'s own files fails the deploy, naming it, and nothing is fetched', function () {
    $transport = submoduleTransport(realworldModules('file:///var/coresmith/apps/other/repo'), realworldTree());
    $deployment = Deployment::factory()->for(submoduleApp())->create();

    expect(fn() => submoduleDeploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->failure_reason)->toContain('The submodule at realworld points at file:///var/coresmith/apps/other/repo')
        ->and(submoduleCommands($transport, '.modules/'))->toBe([]);
});

test('a submodule Coresmith cannot read fails the deploy, saying which one and that without the GitHub app it must be public', function () {
    $transport = submoduleTransport(realworldModules(), realworldTree())
        ->respondTo('.modules/', "fatal: could not read Username for 'https://github.com': terminal prompts disabled", 128);
    $deployment = Deployment::factory()->for(submoduleApp())->create();

    expect(fn() => submoduleDeploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->failure_reason)->toBe("Coresmith couldn't fetch the submodule at realworld from https://github.com/realworld-apps/realworld.git. Without Coresmith's GitHub app, submodules are fetched without a login, so a private one can't be read. Make it public, or copy its files into the repository.");
});

test('a .gitmodules entry the release does not pin to a commit is left out', function () {
    $transport = submoduleTransport(realworldModules(), '');

    submoduleDeploy(Deployment::factory()->for(submoduleApp())->create(), $transport);

    expect(submoduleCommands($transport, '.modules/'))->toBe([]);
});

test('a submodule pinned to a commit its repository no longer has fails the deploy, naming the commit', function () {
    $transport = submoduleTransport(realworldModules(), realworldTree())
        ->respondTo('fetch --quiet origin', 'fatal: remote error: upload-pack: not our ref ' . SUBMODULE_PIN, 128);
    $deployment = Deployment::factory()->for(submoduleApp())->create();

    expect(fn() => submoduleDeploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->failure_reason)->toBe('The submodule at realworld is pinned to commit 2558884, which https://github.com/realworld-apps/realworld.git no longer has. Point the submodule at a commit that exists, push, then deploy again.');
});

test('a submodule that fails for a reason Coresmith does not recognise says which one and where the output is', function () {
    $transport = submoduleTransport(realworldModules(), realworldTree())
        ->respondTo('.modules/', 'fatal: something unexpected', 128);
    $deployment = Deployment::factory()->for(submoduleApp())->create();

    expect(fn() => submoduleDeploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->failure_reason)->toBe("Coresmith couldn't fetch the submodule at realworld from https://github.com/realworld-apps/realworld.git. Git's output is in the log.");
});

test('a submodule\'s own submodules are fetched into it, resolved against its address', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(SUBMODULE_RELEASE . '/.gitmodules', realworldModules())
        ->respondTo('realworld/.gitmodules', "submodule.spec.path=spec\nsubmodule.spec.url=../spec.git\n")
        ->respondTo("ls-tree 'main'", realworldTree())
        ->respondTo('ls-tree ' . SUBMODULE_PIN, "160000 commit 0123456789abcdef0123456789abcdef01234567\tspec\n");

    submoduleDeploy(Deployment::factory()->for(submoduleApp())->create(), $transport);

    $nested = array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'archive 0123456789abcdef0123456789abcdef01234567')));

    expect(implode("\n", submoduleCommands($transport, 'clone --bare')))->toContain("'https://github.com/realworld-apps/spec.git'")
        ->and($nested[0] ?? '')->toContain("tar -x -C '" . SUBMODULE_RELEASE_PATH . "/realworld/spec'");
});

test('submodules nested more than three deep are left out', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo('/.gitmodules', "submodule.deeper.path=deeper\nsubmodule.deeper.url=../deeper.git\n")
        ->respondTo(' ls-tree ', '160000 commit ' . SUBMODULE_PIN . "\tdeeper\n");

    submoduleDeploy(Deployment::factory()->for(submoduleApp())->create(), $transport);

    expect(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'archive ' . SUBMODULE_PIN)))->toHaveCount(3);
});
