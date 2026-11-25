<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\ServerStatus;
use App\Jobs\DeployApplication;
use App\Jobs\UpdateServerRuntime;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Services\Panel\ActivityFeed;
use App\Services\Server\ServerRuntimes;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    config(['coresmith.runtimes.php.versions' => [
        ['line' => '8.4', 'latest' => '8.4.9', 'active_until' => '2030-12-31', 'eol_at' => '2032-12-31'],
        ['line' => '8.1', 'latest' => '8.1.34', 'active_until' => '2023-11-25', 'eol_at' => '2025-12-31'],
    ]]);
});

/**
 * @return array{Team, Server}
 */
function serverRuntimesFleet(): array
{
    $team = Team::factory()->owned()->create();

    return [$team, Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active])];
}

function serverRuntimesApp(Server $server, string $name, string $php, ?array $resolved = null): Application
{
    return Application::factory()->for($server->team)->for($server)->create([
        'name' => $name,
        'type' => ApplicationType::Php,
        'php_version' => $php,
        'stack' => $resolved === null ? null : ['runtimes' => ['php' => ['runtime' => 'php', 'version' => $php, ...$resolved]]],
    ]);
}

test('an app whose repository asks for an exact patch stays pinned, and one that asks for a line moves', function (array $resolved, ?string $pinned) {
    [, $server] = serverRuntimesFleet();
    $application = serverRuntimesApp($server, 'shop', '8.4.1', $resolved);

    expect(app(ServerRuntimes::class)->exactPin($application, 'php'))->toBe($pinned);
})->with([
    'exact in .tool-versions' => [['source' => '.tool-versions', 'detail' => '8.4.1'], '.tool-versions'],
    'exact with an equals sign' => [['source' => 'composer.json', 'detail' => '==8.4.1'], 'composer.json'],
    'a range in composer.json' => [['source' => 'composer.json', 'detail' => '^8.4'], null],
    'a line in .tool-versions' => [['source' => '.tool-versions', 'detail' => '8.4'], null],
    'picked on the app page' => [['source' => 'pin', 'detail' => null], null],
    'the recommended version' => [['source' => 'default', 'detail' => null], null],
]);

test('the job installs the patch, then deploys each chosen app still on the old version, and logs it', function () {
    Queue::fake();
    [$team, $server] = serverRuntimesFleet();
    $shop = serverRuntimesApp($server, 'shop', '8.4.1');
    $moved = serverRuntimesApp($server, 'moved', '8.4.9');
    $transport = (new FakeSSHTransport)->respondTo('test -d', "no\n");
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new UpdateServerRuntime($server, 'php', '8.4.1', '8.4.9', [$shop->id, $moved->id], $team->owner), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect(implode("\n", $transport->commands()))->toContain('asdf install')
        ->and($server->fresh()->installed_runtimes)->toBe(['php' => ['8.4.9']])
        ->and(Deployment::query()->pluck('application_id')->all())->toBe([$shop->id])
        ->and(UpdateServerRuntime::state($server))->toBeNull()
        ->and(Activity::query()->where('description', 'runtime_updated')->sole()->properties->all())->toEqual(['runtime' => 'PHP', 'from' => '8.4.1', 'to' => '8.4.9', 'apps' => 1]);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a failed install remembers why, deploys nothing, and the activity says so', function () {
    Queue::fake();
    [$team, $server] = serverRuntimesFleet();
    $shop = serverRuntimesApp($server, 'shop', '8.4.1');

    (new UpdateServerRuntime($server, 'php', '8.4.1', '8.4.9', [$shop->id], $team->owner))->failed(new RuntimeException('connection refused'));

    expect(UpdateServerRuntime::state($server))->toMatchArray(['label' => 'PHP 8.4.9', 'state' => 'failed', 'reason' => null])
        ->and(Deployment::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'runtime_update_failed')->sole()->properties->all())->toEqual(['runtime' => 'PHP', 'from' => '8.4.1', 'to' => '8.4.9', 'reason' => null]);
});

test('the activity feed describes runtime updates in plain words', function () {
    [$team, $server] = serverRuntimesFleet();

    activity()->performedOn($server)->withProperties(['runtime' => 'PHP', 'from' => '8.4.1', 'to' => '8.4.9', 'apps' => 2])->log('runtime_updated');
    activity()->performedOn($server)->withProperties(['runtime' => 'PHP', 'from' => '8.4.1', 'to' => '8.4.9', 'reason' => null])->log('runtime_update_failed');

    $sentences = collect((new ActivityFeed)->forTeam($team))->map(fn(array $row): string => serverRuntimesSentence($row))->all();

    expect($sentences)->toContain('PHP 8.4.1 updated to 8.4.9 on web-1, 2 apps deploying')
        ->toContain("PHP 8.4.9 couldn't be installed on web-1, so no app was deployed");
});

function serverRuntimesSentence(array $row): string
{
    return implode('', array_column($row['parts'], 'text'));
}
