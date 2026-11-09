<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a server belongs to a team', function () {
    $team = Team::factory()->create();

    expect(Server::factory()->for($team)->create()->team->id)->toBe($team->id);
});

test('runtimes belong to the coresmith user, except on the machine Coresmith runs on, where that user is Coresmith itself', function () {
    expect(Server::factory()->create()->fresh()->runtimeUser())->toBe('coresmith')
        ->and(Server::factory()->create(['runs_coresmith' => true])->fresh()->runtimeUser())->toBe('coresmith-runtime');
});

test('a server defaults to pending status', function () {
    expect(Server::factory()->create()->fresh()->status)->toBe(ServerStatus::Pending);
});

test('the ssh private key is encrypted at rest and readable through the model', function () {
    $server = Server::factory()->create(['ssh_private_key' => 'PRIVATE-KEY-MATERIAL']);

    $raw = DB::table('servers')->where('id', $server->id)->value('ssh_private_key');

    expect($raw)->not->toContain('PRIVATE-KEY-MATERIAL')
        ->and($server->fresh()->ssh_private_key)->toBe('PRIVATE-KEY-MATERIAL');
});

test('the ssh public key is stored in plain text for display', function () {
    $server = Server::factory()->create(['ssh_public_key' => 'ssh-ed25519 AAAA']);

    expect(DB::table('servers')->where('id', $server->id)->value('ssh_public_key'))->toBe('ssh-ed25519 AAAA');
});

test('installed runtimes round-trip as an array', function () {
    $server = Server::factory()->create(['installed_runtimes' => ['php' => '8.5.5']]);

    expect($server->fresh()->installed_runtimes)->toBe(['php' => '8.5.5']);
});

test('a server is soft deleted', function () {
    $server = Server::factory()->create();
    $server->delete();

    expect(Server::withTrashed()->find($server->id))->not->toBeNull();
});

test('an ssh key belongs to a user', function () {
    $user = User::factory()->create();

    expect(SshKey::factory()->for($user)->create()->user->id)->toBe($user->id);
});
