<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Services\Server\CoresmithKey;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeSSHTransport;

function releaseServersServer(Team $team, string $name, array $attributes = []): Server
{
    return Server::factory()->for($team)->create([
        'name' => $name,
        'status' => ServerStatus::Active,
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'last_connected_at' => now(),
        'ip_address' => '192.0.2.10',
        ...$attributes,
    ]);
}

function releaseServersUsing(FakeSSHTransport $transport): FakeSSHTransport
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return $transport;
}

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test("each server Coresmith reached loses its key, and the ones it can't reach are reported with the line to remove", function () {
    $team = Team::factory()->create();
    releaseServersServer($team, 'anvil-01');
    $never = releaseServersServer($team, 'outpost-02', ['last_connected_at' => null, 'ssh_user' => 'deploy']);
    $transport = releaseServersUsing(new FakeSSHTransport);

    $this->artisan('coresmith:release-servers')
        ->expectsOutput("Took Coresmith's key off anvil-01.")
        ->expectsOutput("Couldn't reach outpost-02. Remove the line ending in " . CoresmithKey::comment($never) . ' from /home/deploy/.ssh/authorized_keys there.')
        ->expectsOutput("Took Coresmith's key off 1 of 2 servers.")
        ->assertSuccessful();

    expect(implode("\n", $transport->commands()))->toContain('authorized_keys');
});

test('a server that refuses the change is reported too', function () {
    $team = Team::factory()->create();
    releaseServersServer($team, 'anvil-01');
    releaseServersUsing((new FakeSSHTransport)->respondTo('authorized_keys', '', 1));

    $this->artisan('coresmith:release-servers')
        ->expectsOutput("Took Coresmith's key off 0 of 1 servers.")
        ->assertSuccessful();
});

test('this machine and destroyed servers are skipped', function () {
    $team = Team::factory()->create();
    releaseServersServer($team, 'beelink', ['runs_coresmith' => true, 'ip_address' => '127.0.0.1']);
    releaseServersServer($team, 'gone-01')->delete();
    releaseServersServer($team, 'anvil-01');
    $transport = releaseServersUsing(new FakeSSHTransport);

    $this->artisan('coresmith:release-servers')
        ->expectsOutput("Took Coresmith's key off anvil-01.")
        ->expectsOutput("Took Coresmith's key off 1 of 1 servers.")
        ->doesntExpectOutputToContain('beelink')
        ->doesntExpectOutputToContain('gone-01')
        ->assertSuccessful();
});

test('every team’s servers are released, since the whole install goes', function () {
    releaseServersServer(Team::factory()->create(), 'anvil-01');
    releaseServersServer(Team::factory()->create(), 'acme-web');
    releaseServersUsing(new FakeSSHTransport);

    $this->artisan('coresmith:release-servers')
        ->expectsOutput("Took Coresmith's key off 2 of 2 servers.")
        ->assertSuccessful();
});

test('the report is written for the uninstaller', function () {
    $team = Team::factory()->create();
    releaseServersServer($team, 'anvil-01');
    $never = releaseServersServer($team, 'outpost-02', ['last_connected_at' => null]);
    releaseServersUsing(new FakeSSHTransport);
    $folder = sys_get_temp_dir() . '/release-servers-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($folder);

    try {
        $this->artisan('coresmith:release-servers', ['--report' => "{$folder}/released.json"])->assertSuccessful();

        expect(json_decode((string) file_get_contents("{$folder}/released.json"), true))->toBe([
            'removed' => ['anvil-01'],
            'failed' => [['name' => 'outpost-02', 'file' => '/root/.ssh/authorized_keys', 'comment' => CoresmithKey::comment($never)]],
        ])
            ->and(substr(sprintf('%o', fileperms("{$folder}/released.json")), -4))->toBe('0640');
    } finally {
        File::deleteDirectory($folder);
    }
});
