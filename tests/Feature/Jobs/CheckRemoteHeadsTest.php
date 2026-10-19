<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\CheckRemoteHeads;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Services\AutoDeploy\AutoDeployStatus;
use App\Services\AutoDeploy\LsRemote;
use App\Services\Deployment\GitFailure;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeSSHTransport;

function remoteHeadsServer(array $attributes = []): Server
{
    return Server::factory()->active()->create(array_merge(['name' => 'forge-01'], $attributes));
}

function remoteHeadsApp(array $attributes = []): Application
{
    $server = isset($attributes['server_id']) ? Server::query()->findOrFail($attributes['server_id']) : remoteHeadsServer();

    return Application::factory()->for($server->team)->for($server)->create(array_merge([
        'slug' => 'my-app',
        'repository_url' => 'https://github.com/monoven/site.git',
        'repository_branch' => 'main',
        'quick_deploy' => true,
    ], $attributes));
}

function remoteHeadsSha(string $character): string
{
    return str_repeat($character, 40);
}

function remoteHeadsJob(Application ...$applications): CheckRemoteHeads
{
    return new CheckRemoteHeads($applications[0]->server, array_map(fn(Application $application): string => $application->id, $applications));
}

function remoteHeadsCheck(FakeSSHTransport $transport, CheckRemoteHeads $job): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([unserialize(serialize($job)), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function remoteHeadsRun(FakeSSHTransport $transport, Application ...$applications): void
{
    remoteHeadsCheck($transport, remoteHeadsJob(...$applications));
}

function remoteHeadsIndex(FakeSSHTransport $transport, string $needle): int|false
{
    return collect($transport->commands())->search(fn(string $command): bool => str_contains($command, $needle));
}

function remoteHeadsGit(string $directory, string $command): ProcessResult
{
    return Process::path($directory)
        ->env([
            'PATH' => $directory . '/bin:' . getenv('PATH'),
            'GIT_CONFIG_GLOBAL' => $directory . '/gitconfig',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_DIR' => false,
        ])
        ->run(['bash', '-c', $command]);
}

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:05:00'));
});

test('a public repository is checked with ls-remote on its own server', function () {
    $application = remoteHeadsApp();
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', remoteHeadsSha('c') . "\trefs/heads/main\n");

    remoteHeadsRun($transport, $application);

    $fresh = $application->fresh();
    $deployment = Deployment::query()->where('requested_commit', remoteHeadsSha('c'))->sole();

    expect($transport->commands())->toContain("GIT_TERMINAL_PROMPT=0 timeout 45 git ls-remote --exit-code -- 'https://github.com/monoven/site.git' 'refs/heads/main'")
        ->and(implode("\n", $transport->commands()))->not->toContain('/etc/coresmith/deploy-keys')
        ->and($transport->uploads())->toBe([])
        ->and($transport->budgetFor('git ls-remote'))->toBe(60)
        ->and($fresh->repository_head)->toBe(remoteHeadsSha('c'))
        ->and($fresh->repository_head_source)->toBe('poll')
        ->and($fresh->poll_error)->toBeNull()
        ->and($fresh->polled_at?->toDateTimeString())->toBe('2026-10-19 14:05:00')
        ->and($deployment->trigger)->toBe('poll');

    Queue::assertPushedOn('deployments', DeployApplication::class);
});

test('a head the app already knows is not observed again, only reconciled', function () {
    $application = remoteHeadsApp(['repository_head' => remoteHeadsSha('c'), 'repository_head_source' => 'push', 'repository_head_at' => now()->subHour()]);
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', remoteHeadsSha('c') . "\trefs/heads/main\n");

    remoteHeadsRun($transport, $application);

    $fresh = $application->fresh();

    expect($fresh->repository_head_source)->toBe('push')
        ->and($fresh->repository_head_at?->toDateTimeString())->toBe('2026-10-19 13:05:00')
        ->and(Deployment::query()->where('requested_commit', remoteHeadsSha('c'))->sole()->trigger)->toBe('push');
});

test('a repository cloned over ssh is checked with its deploy key at a path of its own', function () {
    $application = remoteHeadsApp(['repository_url' => 'git@github.com:monoven/site.git']);
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', remoteHeadsSha('c') . "\trefs/heads/main\n");

    remoteHeadsRun($transport, $application);

    $commands = $transport->commands();
    $reserve = array_search("install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app.poll'", $commands, true);
    $check = remoteHeadsIndex($transport, 'git ls-remote');
    $remove = array_search("rm -f '/etc/coresmith/deploy-keys/my-app.poll'", $commands, true);

    expect($transport->uploads())->toHaveKey('/etc/coresmith/deploy-keys/my-app.poll')
        ->and($transport->uploads()['/etc/coresmith/deploy-keys/my-app.poll'])->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($transport->uploads())->not->toHaveKey('/etc/coresmith/deploy-keys/my-app')
        ->and($commands)->toContain("mkdir -p '/etc/coresmith/deploy-keys' && chown root:root '/etc/coresmith/deploy-keys' && chmod 700 '/etc/coresmith/deploy-keys'")
        ->and($commands[$check])->toBe("GIT_SSH_COMMAND='ssh -i '\\''/etc/coresmith/deploy-keys/my-app.poll'\\'' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile='\\''/etc/coresmith/deploy-keys/known_hosts'\\''' GIT_TERMINAL_PROMPT=0 timeout 45 git ls-remote --exit-code -- 'git@github.com:monoven/site.git' 'refs/heads/main'")
        ->and($reserve)->toBeLessThan($check)
        ->and($remove)->toBeGreaterThan($check)
        ->and($commands)->not->toContain("rm -f '/etc/coresmith/deploy-keys/my-app'")
        ->and($application->fresh()->repository_head)->toBe(remoteHeadsSha('c'));
});

test('the poll key is removed even when ls-remote fails', function (Closure $fail, string $error) {
    $application = remoteHeadsApp(['repository_url' => 'git@github.com:monoven/site.git']);
    $transport = $fail(new FakeSSHTransport);

    remoteHeadsRun($transport, $application);

    expect(remoteHeadsIndex($transport, "rm -f '/etc/coresmith/deploy-keys/my-app.poll'"))->toBeGreaterThan(remoteHeadsIndex($transport, 'git ls-remote'))
        ->and($application->fresh()->poll_error)->toBe($error);
})->with([
    'git refuses' => [
        fn(FakeSSHTransport $transport) => $transport->respondTo('git ls-remote', "fatal: Could not read from remote repository.\n", 128),
        "Coresmith could not read the repository on github.com. If it is private, connect your Git provider in Settings, Git, or add the app's deploy key to the repository.",
    ],
    'the connection drops' => [
        fn(FakeSSHTransport $transport) => $transport->dropConnectionOn('git ls-remote'),
        'Coresmith could not connect to forge-01.',
    ],
]);

test('a missing branch is said plainly', function (string $output, int $exitCode) {
    $application = remoteHeadsApp();
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', $output, $exitCode);

    remoteHeadsRun($transport, $application);

    expect($application->fresh()->poll_error)->toBe('Branch main is not in the repository.')
        ->and($application->fresh()->repository_head)->toBeNull()
        ->and(Deployment::count())->toBe(0);
})->with([
    'no ref matches' => ['', 2],
    'only a longer branch ends with it' => [remoteHeadsSha('d') . "\trefs/heads/feature/refs/heads/main\n", 0],
]);

test('a failure git does not explain is said in the poll\'s own words', function (string $output, int $exitCode, string $error) {
    $application = remoteHeadsApp();
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', $output, $exitCode);

    remoteHeadsRun($transport, $application);

    expect($application->fresh()->poll_error)->toBe($error)
        ->and(app(AutoDeployStatus::class)->describe($application->fresh()))->toBe("Checks main for new commits every 5 minutes from forge-01. Could not check main at 14:05: {$error}");
})->with([
    'ls-remote ran out of time' => ['', 124, 'the repository did not answer within 45 seconds.'],
    'any other exit' => ["fatal: protocol error: bad line length character: Welc\n", 1, 'git ls-remote failed with exit code 1. A deploy shows the full output.'],
    'ran out of time, and git said why' => ["fatal: unable to access 'https://github.com/monoven/site.git/': Failed to connect to github.com port 443 after 44000 ms: Connection timed out\n", 124, 'The server could not reach github.com. Check its firewall allows outbound HTTPS and SSH, then deploy again.'],
]);

test('setting up the poll key is not a connection failure', function (Closure $fail, string $sshUser, string $error) {
    $application = remoteHeadsApp(['server_id' => remoteHeadsServer(['ssh_user' => $sshUser])->id, 'repository_url' => 'git@github.com:monoven/site.git']);
    $transport = $fail((new FakeSSHTransport)->respondTo('git ls-remote', remoteHeadsSha('c') . "\trefs/heads/main\n"));

    remoteHeadsRun($transport, $application);

    $removal = $sshUser === 'root' ? "rm -f '/etc/coresmith/deploy-keys/my-app.poll'" : "sudo rm -f '/etc/coresmith/deploy-keys/my-app.poll'";

    expect($application->fresh()->poll_error)->toBe($error)
        ->and($application->fresh()->repository_head)->toBeNull()
        ->and($transport->commands())->toContain($removal)
        ->and(Deployment::count())->toBe(0);
})->with([
    'reserving it is refused' => [
        fn(FakeSSHTransport $transport) => $transport->respondTo("install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app.poll'", "install: cannot create regular file '/etc/coresmith/deploy-keys/my-app.poll': Read-only file system\n", 1),
        'root',
        'Coresmith could not set up the deploy key on forge-01.',
    ],
    'writing it fails' => [
        fn(FakeSSHTransport $transport) => $transport->respondTo('then cat ', "bash: line 1: /etc/coresmith/deploy-keys/my-app.poll: Read-only file system\n", 1),
        'deployer',
        'Coresmith could not set up the deploy key on forge-01.',
    ],
    'removing it fails' => [
        fn(FakeSSHTransport $transport) => $transport->respondTo("rm -f '/etc/coresmith/deploy-keys/my-app.poll'", "rm: cannot remove '/etc/coresmith/deploy-keys/my-app.poll': Read-only file system\n", 1),
        'root',
        'Coresmith could not set up the deploy key on forge-01.',
    ],
    'the connection drops while reserving it' => [
        fn(FakeSSHTransport $transport) => $transport->dropConnectionOn('install -m 600 /dev/null'),
        'root',
        'Coresmith could not connect to forge-01.',
    ],
]);

test('a refused repository gives the clone\'s own advice', function () {
    $application = remoteHeadsApp(['repository_url' => 'git@github.com:monoven/site.git']);
    $output = "git@github.com: Permission denied (publickey).\r\nfatal: Could not read from remote repository.\n\nPlease make sure you have the correct access rights\nand the repository exists.\n";
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', $output, 128);

    remoteHeadsRun($transport, $application);

    expect($application->fresh()->poll_error)->toBe(GitFailure::from($output, $application)->reason)
        ->and($application->fresh()->poll_error)->toBe("Coresmith could not read the repository on github.com. If it is private, connect your Git provider in Settings, Git, or add the app's deploy key to the repository.");
});

test('an address that is not a git url is refused before anything runs on the server', function (string $url) {
    $application = remoteHeadsApp(['repository_url' => $url]);
    $transport = new FakeSSHTransport;

    remoteHeadsRun($transport, $application);

    expect($transport->commands())->toBe([])
        ->and($transport->uploads())->toBe([])
        ->and($application->fresh()->poll_error)->toBe("This app's repository address is not a git URL Coresmith can check. Fix it in the app's settings.")
        ->and(Deployment::count())->toBe(0)
        ->and(fn() => app(LsRemote::class)->command($application))->toThrow(InvalidArgumentException::class, "This app's repository address is not a git URL Coresmith can check. Fix it in the app's settings.");
})->with([
    'a value that reads as an option' => '--upload-pack=touch /tmp/coresmith-pwned',
    'git\'s ext transport' => 'ext::sh -c touch% /tmp/coresmith-pwned',
    'a local file' => 'file:///etc/passwd',
    'a space inside it' => 'https://github.com/monoven/site.git --upload-pack=touch',
    'a trailing newline' => "https://github.com/monoven/site.git\n",
    'nothing' => '',
]);

test('the command works with real git, and its -- keeps an address that reads as an option from running', function () {
    $directory = realpath(sys_get_temp_dir()) . '/coresmith-ls-remote-' . Str::lower(Str::random(10));
    $lsRemote = app(LsRemote::class);
    $command = $lsRemote->command(remoteHeadsApp());
    $against = fn(string $url, string $branch = 'main'): ProcessResult => remoteHeadsGit($directory, str_replace(
        ["'https://github.com/monoven/site.git'", "'refs/heads/main'"],
        [escapeshellarg($url), escapeshellarg('refs/heads/' . $branch)],
        $command,
    ));

    File::ensureDirectoryExists($directory . '/bin', 0700);

    try {
        File::put($directory . '/gitconfig', '');
        File::put($directory . '/bin/timeout', "#!/bin/sh\nshift\nexec \"\$@\"\n");
        chmod($directory . '/bin/timeout', 0700);
        remoteHeadsGit($directory, 'git init -q -b main repo && git -C repo -c user.name=Coresmith -c user.email=git@coresmith.invalid commit -q --allow-empty -m first')->throw();

        $head = trim(remoteHeadsGit($directory, 'git -C repo rev-parse main')->throw()->output());
        $found = $against($directory . '/repo');
        $missing = $against($directory . '/repo', 'release/2.0');
        $option = $against('--upload-pack=touch ' . $directory . '/pwned');

        expect($found->exitCode())->toBe(0)
            ->and($lsRemote->parse($found->output(), 'main'))->toBe($head)
            ->and($missing->exitCode())->toBe(2)
            ->and($option->exitCode())->toBe(128)
            ->and($option->errorOutput())->toContain('strange pathname')
            ->and(file_exists($directory . '/pwned'))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});

test('a linked app whose connection was removed is told so by its server', function () {
    $connection = SourceProvider::factory()->githubApp(GitHubApp::factory()->create())->create(['name' => 'monoventech', 'installation_id' => '51842']);
    $application = remoteHeadsApp(['repository_url' => 'https://github.com/monoventech/atlas.git', 'source_provider_id' => $connection->id, 'repository_id' => '70000001']);
    $connection->delete();
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n", 128);

    remoteHeadsRun($transport, $application);

    expect($application->fresh()->poll_error)->toBe("This app's GitHub connection was removed, so the private repository monoventech/atlas could not be read. Connect GitHub again from Settings, Git, then deploy.");
});

test('a server that cannot be reached marks every app it holds', function () {
    $server = remoteHeadsServer();
    $apps = [remoteHeadsApp(['server_id' => $server->id, 'slug' => 'site-one']), remoteHeadsApp(['server_id' => $server->id, 'slug' => 'site-two', 'repository_url' => 'git@github.com:monoven/two.git'])];
    $transport = (new FakeSSHTransport)->failLogin();

    remoteHeadsRun($transport, ...$apps);

    foreach ($apps as $application) {
        expect($application->fresh()->poll_error)->toBe('Coresmith could not connect to forge-01.')
            ->and($application->fresh()->polled_at?->toDateTimeString())->toBe('2026-10-19 14:05:00');
    }

    expect($transport->commands())->toBe([])
        ->and(Deployment::count())->toBe(0);
});

test('a poll error longer than the page can hold is cut to fit', function () {
    $application = remoteHeadsApp(['repository_url' => 'https://github.com/monoven/' . str_repeat('a', 220) . '.git']);
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', "remote: Repository not found.\nfatal: repository not found\n", 128);

    remoteHeadsRun($transport, $application);

    expect(mb_strlen((string) $application->fresh()->poll_error))->toBe(255)
        ->and($application->fresh()->poll_error)->toStartWith('Repository not found at https://github.com/monoven/aaa');
});

test('a server signed in as another user checks as root', function () {
    $server = remoteHeadsServer(['ssh_user' => 'deployer']);
    $apps = [remoteHeadsApp(['server_id' => $server->id, 'repository_url' => 'git@github.com:monoven/app.git']), remoteHeadsApp(['server_id' => $server->id, 'slug' => 'site'])];
    $transport = (new FakeSSHTransport)->respondTo('git ls-remote', remoteHeadsSha('c') . "\trefs/heads/main\n");

    remoteHeadsRun($transport, ...$apps);

    $commands = collect($transport->commands());
    $privileged = $commands->filter(fn(string $command): bool => str_contains($command, '/etc/coresmith/deploy-keys') || str_contains($command, 'git ls-remote'))->values();

    expect($privileged->reject(fn(string $command): bool => str_starts_with($command, 'sudo '))->all())->toBe([])
        ->and($privileged->filter(fn(string $command): bool => str_contains($command, 'git ls-remote'))->count())->toBe(2)
        ->and($commands)->toContain("sudo install -m 600 /dev/null '/etc/coresmith/deploy-keys/my-app.poll'")
        ->and($commands)->toContain("sudo rm -f '/etc/coresmith/deploy-keys/my-app.poll'")
        ->and($commands)->toContain("sudo GIT_TERMINAL_PROMPT=0 timeout 45 git ls-remote --exit-code -- 'https://github.com/monoven/site.git' 'refs/heads/main'")
        ->and($commands->contains(fn(string $command): bool => str_starts_with($command, 'sudo bash -c ') && str_contains($command, 'then cat ') && str_contains($command, 'my-app.poll')))->toBeTrue();
});

test('apps that changed after they were queued are not checked', function (Closure $change) {
    $application = remoteHeadsApp();
    $job = remoteHeadsJob($application);
    $change($application);
    $transport = new FakeSSHTransport;

    remoteHeadsCheck($transport, $job);

    expect($transport->commands())->toBe([])
        ->and(Application::withTrashed()->find($application->id)->polled_at)->toBeNull();
})->with([
    'auto deploy turned off' => [fn(Application $application) => $application->forceFill(['quick_deploy' => false])->saveQuietly()],
    'removed' => [fn(Application $application) => $application->delete()],
    'moved to another server' => [fn(Application $application) => $application->forceFill(['server_id' => remoteHeadsServer(['name' => 'forge-02'])->id])->saveQuietly()],
    'its server no longer active' => [fn(Application $application) => Server::query()->whereKey($application->server_id)->update(['status' => ServerStatus::Disconnected->value])],
]);

test('ls-remote output is matched to the exact branch', function () {
    $output = "Warning: Permanently added 'github.com' (ED25519) to the list of known hosts.\r\n"
        . remoteHeadsSha('a') . "\trefs/heads/release/2.0\r\n"
        . remoteHeadsSha('b') . "\trefs/heads/2.0\n"
        . remoteHeadsSha('c') . "\trefs/tags/main\n";
    $lsRemote = app(LsRemote::class);

    expect($lsRemote->parse($output, 'release/2.0'))->toBe(remoteHeadsSha('a'))
        ->and($lsRemote->parse($output, '2.0'))->toBe(remoteHeadsSha('b'))
        ->and($lsRemote->parse($output, 'main'))->toBeNull()
        ->and($lsRemote->parse(strtoupper(remoteHeadsSha('e')) . "\trefs/heads/main\n", 'main'))->toBeNull()
        ->and(LsRemote::TIMEOUT_SECONDS)->toBe(45);
});

test('one check covers at most five apps and is never queued twice for the same ones', function () {
    $server = remoteHeadsServer();
    $job = new CheckRemoteHeads($server, ['01k0000000000000000000000c', '01k0000000000000000000000a', '01k0000000000000000000000b']);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe("{$server->id}:01k0000000000000000000000a,01k0000000000000000000000b,01k0000000000000000000000c")
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->queue)->toBe('default')
        ->and($job->timeout)->toBe(300)
        ->and($job->tries)->toBe(1)
        ->and(CheckRemoteHeads::APPLICATIONS_PER_JOB)->toBe(5);
});
