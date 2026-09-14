<?php

declare(strict_types=1);

use App\Exceptions\SSHCommandFailedException;
use App\Jobs\PruneReleases;
use App\Jobs\RebootServer;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\ReleasePaths;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function housekeepingWith(FakeSSHTransport $transport, callable $work): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        $work();
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function housekeepingReleaseTree(array $names, string $live): string
{
    $root = realpath(sys_get_temp_dir()) . '/coresmith-prune-' . Str::lower(Str::random(10));
    $releases = $root . '/my-app/releases';

    File::ensureDirectoryExists($releases);

    foreach ($names as $index => $name) {
        File::ensureDirectoryExists($releases . '/' . $name);
        touch($releases . '/' . $name, 1_800_000_000 + $index * 60);
    }

    symlink($releases . '/' . $live, $root . '/my-app/current');

    return $root;
}

function housekeepingPrune(string $root): string
{
    $command = str_replace(ReleasePaths::ROOT, $root, PruneReleases::command(new Application(['slug' => 'my-app'])));

    return trim(Process::run(['bash', '-c', $command])->throw()->output());
}

function housekeepingRemaining(string $root): array
{
    return collect(File::directories($root . '/my-app/releases'))->map(fn(string $path): string => basename($path))->sort()->values()->all();
}

test('pruning keeps the newest releases and the live one even when the live one is the oldest', function () {
    $names = ['20260901-000000', '20260902-000000', '20260903-000000', '20260904-000000', '20260905-000000', '20260906-000000', '20260907-000000', '20260908-000000'];
    $root = housekeepingReleaseTree($names, live: '20260901-000000');

    try {
        $removed = housekeepingPrune($root);

        expect($removed)->toBe('2')
            ->and(housekeepingRemaining($root))->toBe(['20260901-000000', '20260904-000000', '20260905-000000', '20260906-000000', '20260907-000000', '20260908-000000'])
            ->and(is_dir($root . '/my-app/current'))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

test('pruning removes every release beyond the newest five when the live one is among them', function () {
    $names = ['20260901-000000', '20260902-000000', '20260903-000000', '20260904-000000', '20260905-000000', '20260906-000000', '20260907-000000', '20260908-000000'];
    $root = housekeepingReleaseTree($names, live: '20260908-000000');

    try {
        expect(housekeepingPrune($root))->toBe('3')
            ->and(housekeepingRemaining($root))->toBe(['20260904-000000', '20260905-000000', '20260906-000000', '20260907-000000', '20260908-000000']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('pruning an app with five releases or fewer removes nothing', function () {
    $names = ['20260901-000000', '20260902-000000', '20260903-000000'];
    $root = housekeepingReleaseTree($names, live: '20260902-000000');

    try {
        expect(housekeepingPrune($root))->toBe('0')
            ->and(housekeepingRemaining($root))->toBe($names);
    } finally {
        File::deleteDirectory($root);
    }
});

test('the prune command resolves the live release before deciding what to remove', function () {
    $command = PruneReleases::command(new Application(['slug' => 'my-app']));

    expect($command)->toStartWith('current=$(readlink -f /var/coresmith/apps/my-app/current 2>/dev/null || true); ')
        ->and($command)->toContain('tail -n +' . (ReleasePaths::KEEP_RELEASES + 1))
        ->and($command)->toContain('[ "$dir" = "$current" ] || { rm -rf "$dir" && echo x; }');
});

test('pruning a server runs once per application and logs the total removed', function () {
    $server = Server::factory()->create();
    Application::factory()->for($server)->create(['slug' => 'alpha']);
    Application::factory()->for($server)->create(['slug' => 'beta']);
    $transport = (new FakeSSHTransport)
        ->respondTo('apps/alpha/current', "2\n")
        ->respondTo('apps/beta/current', "       3\n");

    housekeepingWith($transport, fn() => (new PruneReleases($server))->handle(app(SSHConnectionManager::class)));

    $activity = Activity::query()->where('description', 'releases_pruned')->sole();

    expect($transport->commands())->toHaveCount(2)
        ->and($activity->subject_id)->toBe($server->id)
        ->and($activity->properties->toArray())->toBe(['removed' => 5, 'kept' => ReleasePaths::KEEP_RELEASES])
        ->and($transport->connected())->toBeFalse();
});

test('pruning a server with no applications still records that nothing was removed', function () {
    $server = Server::factory()->create();
    $transport = new FakeSSHTransport;

    housekeepingWith($transport, fn() => (new PruneReleases($server))->handle(app(SSHConnectionManager::class)));

    expect($transport->commands())->toBe([])
        ->and(Activity::query()->where('description', 'releases_pruned')->sole()->properties['removed'])->toBe(0);
});

test('a prune that fails on the server is not logged as done', function () {
    $server = Server::factory()->create();
    Application::factory()->for($server)->create(['slug' => 'alpha']);
    $transport = (new FakeSSHTransport)->respondTo('apps/alpha/current', 'rm: cannot remove', 1);

    expect(fn() => housekeepingWith($transport, fn() => (new PruneReleases($server))->handle(app(SSHConnectionManager::class))))
        ->toThrow(SSHCommandFailedException::class);

    expect(Activity::query()->where('description', 'releases_pruned')->exists())->toBeFalse()
        ->and($transport->connected())->toBeFalse();
});

test('a reboot is scheduled a few seconds out so the command can return', function () {
    $server = Server::factory()->create(['ssh_user' => 'root']);
    $transport = new FakeSSHTransport;

    housekeepingWith($transport, fn() => (new RebootServer($server))->handle(app(SSHConnectionManager::class)));

    expect($transport->commands())->toBe(['systemd-run --on-active=5 --timer-property=AccuracySec=1s systemctl reboot'])
        ->and(Activity::query()->where('description', 'server_rebooted')->sole()->subject_id)->toBe($server->id)
        ->and($transport->connected())->toBeFalse();
});

test('a reboot as a non root user goes through sudo', function () {
    $server = Server::factory()->create(['ssh_user' => 'coresmith']);
    $transport = new FakeSSHTransport;

    housekeepingWith($transport, fn() => (new RebootServer($server))->handle(app(SSHConnectionManager::class)));

    expect($transport->commands())->toBe(['sudo ' . RebootServer::COMMAND]);
});

test('a reboot the server refused is not logged', function () {
    $server = Server::factory()->create();
    $transport = (new FakeSSHTransport)->respondTo('systemd-run', 'Failed to start transient timer unit', 1);

    expect(fn() => housekeepingWith($transport, fn() => (new RebootServer($server))->handle(app(SSHConnectionManager::class))))
        ->toThrow(SSHCommandFailedException::class);

    expect(Activity::query()->where('description', 'server_rebooted')->exists())->toBeFalse();
});
