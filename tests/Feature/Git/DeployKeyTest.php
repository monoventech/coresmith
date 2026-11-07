<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\DeployKeyManager;
use App\Services\Panel\Formats;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\KeyPairGenerator;
use Illuminate\Support\Facades\DB;

function deployKeys(): DeployKeyManager
{
    return new DeployKeyManager(new KeyPairGenerator);
}

function deployKeyApp(Team $team, Server $server, string $url): Application
{
    return Application::factory()->for($team)->for($server)->create(['repository_url' => $url]);
}

function deployKeyMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('an ssh repository url is treated as private', function (string $url, bool $expected) {
    $application = Application::factory()->make(['repository_url' => $url]);

    expect(deployKeys()->usesDeployKey($application))->toBe($expected);
})->with([
    ['git@github.com:monoven/coresmith.git', true],
    ['git@gitlab.com:group/project.git', true],
    ['git@bitbucket.org:team/repo.git', true],
    ['https://github.com/monoven/coresmith.git', false],
    ['http://example.com/repo.git', false],
]);

test('a deploy key is generated once and reused', function () {
    $application = Application::factory()->create();

    $first = deployKeys()->ensure($application);
    $second = deployKeys()->ensure($first->fresh());

    expect($first->deploy_key_public)->toStartWith('ssh-ed25519 ')
        ->and($first->deploy_key_private)->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($second->deploy_key_private)->toBe($first->deploy_key_private);
});

test('the private deploy key is encrypted at rest', function () {
    $application = deployKeys()->ensure(Application::factory()->create());

    $raw = DB::table('applications')->where('id', $application->getKey())->value('deploy_key_private');

    expect($raw)->not->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($application->fresh()->deploy_key_private)->toContain('BEGIN OPENSSH PRIVATE KEY');
});

test('each application gets its own key so one leak cannot reach another repository', function () {
    $a = deployKeys()->ensure(Application::factory()->create());
    $b = deployKeys()->ensure(Application::factory()->create());

    expect($a->deploy_key_private)->not->toBe($b->deploy_key_private)
        ->and($a->deploy_key_public)->not->toBe($b->deploy_key_public);
});

test('the key file is written per application under the root only directory', function () {
    $application = Application::factory()->create(['slug' => 'my-app']);

    expect(deployKeys()->keyPath($application))->toBe(DeployKeyManager::KEY_DIR . '/my-app');
});

test('the key directory is closed to other users before the key is written', function () {
    $application = Application::factory()->create(['slug' => 'my-app']);
    $commands = deployKeys()->installCommands($application);

    expect($commands[0])->toContain('chmod 700')
        ->and($commands[1])->toContain('install -m 600')
        ->and(deployKeys()->removeCommand($application))->toContain('rm -f');
});

test('the key directory stays root only whichever user signs in, because git clones as root', function () {
    $server = Server::factory()->create(['ssh_user' => 'deployer']);
    $application = Application::factory()->for($server)->create(['slug' => 'my-app']);

    expect(deployKeys()->prepareDirectory($application))->toBe("mkdir -p '/etc/coresmith/deploy-keys' && chown root:root '/etc/coresmith/deploy-keys' && chmod 700 '/etc/coresmith/deploy-keys'");
});

test('git is told to use only this key and to pin the host', function () {
    $application = Application::factory()->create(['slug' => 'my-app']);
    $command = deployKeys()->withKey($application, 'git clone --bare a b');

    expect($command)->toStartWith('GIT_SSH_COMMAND=')
        ->and($command)->toContain('IdentitiesOnly=yes')
        ->and($command)->toContain('StrictHostKeyChecking=accept-new')
        ->and($command)->toEndWith('git clone --bare a b');
});

test('a poll key has a path of its own', function () {
    $application = Application::factory()->create(['slug' => 'my-app']);
    $path = deployKeys()->pollKeyPath($application);

    expect(DeployKeyManager::POLL_SUFFIX)->toBe('.poll')
        ->and($path)->toBe('/etc/coresmith/deploy-keys/my-app.poll')
        ->and(deployKeys()->sshCommand($application, $path))->toContain("-i '/etc/coresmith/deploy-keys/my-app.poll'")
        ->and(deployKeys()->withKey($application, 'git x', $path))->toContain("-i '\\''/etc/coresmith/deploy-keys/my-app.poll'\\''")
        ->and(deployKeys()->withKey($application, 'git x', $path))->toEndWith(' git x')
        ->and(deployKeys()->reserveFile($application, $path))->toBe("install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app.poll'")
        ->and(deployKeys()->removeCommand($application, $path))->toBe("rm -f '/etc/coresmith/deploy-keys/my-app.poll'")
        ->and(deployKeys()->withKey($application, 'git x'))->toContain("-i '\\''/etc/coresmith/deploy-keys/my-app'\\'' ")
        ->and(deployKeys()->reserveFile($application))->toBe("install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app'")
        ->and(deployKeys()->removeCommand($application))->toBe("rm -f '/etc/coresmith/deploy-keys/my-app'");
});

test('a traversing slug cannot escape the key directory', function () {
    deployKeys()->keyPath(Application::factory()->make(['slug' => '../../root/.ssh/authorized_keys']));
})->throws(UnsafeNameException::class);

test('the private deploy key is never sent to the browser', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = deployKeyApp($team, $server, 'git@github.com:monoven/coresmith.git');

    $response = $this->actingAs(deployKeyMember($team, TeamRole::Admin))
        ->get(route('servers.applications.show', [$server, $application]))
        ->assertOk();

    expect($response->getContent())->not->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($response->getContent())->not->toContain('deploy_key_private');
});

test('the public deploy key is offered for an ssh repository', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = deployKeyApp($team, $server, 'git@github.com:monoven/coresmith.git');

    $response = $this->actingAs(deployKeyMember($team, TeamRole::Admin))
        ->get(route('servers.applications.show', [$server, $application]));

    $public = (string) $application->fresh()->deploy_key_public;

    expect($public)->toStartWith('ssh-ed25519 ');

    $response->assertInertia(fn($page) => $page
        ->where('application.deploy_key.public_key', $public)
        ->where('application.deploy_key.fingerprint', HostKeyFingerprint::fromPublicKey($public))
        ->where('application.deploy_key.short', Formats::fingerprint(HostKeyFingerprint::fromPublicKey($public)))
        ->where('application.deploy_key.repository', 'monoven/coresmith'));
});

test('a public repository is offered no deploy key', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = deployKeyApp($team, $server, 'https://github.com/monoven/coresmith.git');

    $this->actingAs(deployKeyMember($team, TeamRole::Admin))
        ->get(route('servers.applications.show', [$server, $application]))
        ->assertInertia(fn($page) => $page->where('application.deploy_key', null));

    expect($application->fresh()->deploy_key_private)->toBeNull();
});

test('a member can read the public deploy key but never the private one', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = deployKeyApp($team, $server, 'git@github.com:monoven/coresmith.git');

    $response = $this->actingAs(deployKeyMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$server, $application]))
        ->assertOk();

    expect($response->getContent())->toContain('ssh-ed25519')
        ->and($response->getContent())->not->toContain('BEGIN OPENSSH PRIVATE KEY');
});

test('an ssh form repository url is accepted when creating an application', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(deployKeyMember($team, TeamRole::Admin))
        ->post(route('servers.applications.store', $server), [
            'name' => 'Private App',
            'type' => 'php',
            'domain' => 'private.example.com',
            'repository_url' => 'git@github.com:monoven/private.git',
            'repository_branch' => 'main',
            'directory' => '/',
            'web_directory' => '/public',
            'app_size' => 'small',
            'php_version' => '8.5.5',
        ])
        ->assertRedirect();

    expect(Application::where('name', 'Private App')->first()->repository_url)
        ->toBe('git@github.com:monoven/private.git');
});

test('a repository url that is neither https nor ssh form is rejected', function (string $url) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(deployKeyMember($team, TeamRole::Admin))
        ->post(route('servers.applications.store', $server), [
            'name' => 'App',
            'type' => 'php',
            'repository_url' => $url,
            'repository_branch' => 'main',
            'directory' => '/',
            'web_directory' => '/public',
            'app_size' => 'small',
        ])
        ->assertSessionHasErrors('repository_url');
})->with([
    'file:///etc/passwd',
    'ext::sh -c cat% /etc/passwd',
    'git@github.com:owner/repo.git; rm -rf /',
    '--upload-pack=touch /tmp/pwned',
]);
