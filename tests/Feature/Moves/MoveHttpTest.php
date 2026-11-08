<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\TakeOverServer;
use App\Models\Application;
use App\Models\MoveCode;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\MoveFile;
use App\Services\Moves\TeamMove;
use App\Services\SSH\KeyPairGenerator;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CloudBilling;

function moveHttpCloudTeam(): Team
{
    $team = Team::factory()->onTrial()->owned()->create(['name' => 'Acme', 'slug' => 'acme']);
    $server = Server::factory()->active()->create(['team_id' => $team->id, 'name' => 'web-01', 'host_key_fingerprint' => 'SHA256:web']);
    Application::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    return $team->fresh();
}

function moveHttpOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function moveHttpCodeText(?User $user = null): string
{
    $codes = app(MoveCodes::class);

    return $codes->text($codes->create($user ?? User::factory()->create()));
}

function moveHttpPanelOwner(): User
{
    return moveHttpOwner(Team::factory()->owned()->create(['name' => 'Home']));
}

function moveHttpPayload(): array
{
    $key = (new KeyPairGenerator)->generate('cloud');

    return [
        'id' => 'b2b0c0de-0000-4000-8000-000000000002',
        'from' => 'https://app.coresmith.io',
        'team' => ['name' => 'Acme', 'slug' => 'acme'],
        'members' => [],
        'cloud_providers' => [],
        'servers' => [['ref' => 's1', 'cloud_provider' => null, 'name' => 'web-01', 'ip_address' => '203.0.113.50', 'ssh_port' => 22, 'ssh_user' => 'root', 'ssh_private_key' => $key->privateKey, 'ssh_public_key' => $key->publicKey, 'host_key_fingerprint' => 'SHA256:web', 'os_name' => 'ubuntu', 'os_version' => '24.04', 'provisioning_phase' => 8]],
        'source_providers' => [],
        'applications' => [['ref' => 'a1', 'server' => 's1', 'source_provider' => null, 'name' => 'site', 'slug' => 'acme-site-moved', 'type' => 'php', 'system_user' => 'coresmith-app-site', 'base_port' => 8100, 'status' => 'active']],
        'webhook_endpoints' => [],
        'databases' => [],
        'backup_destination' => null,
    ];
}

function moveHttpUploaded(MoveCode $code, ?MoveCode $sealedTo = null): UploadedFile
{
    $codes = app(MoveCodes::class);
    $file = app(MoveFile::class)->seal(moveHttpPayload(), $codes->read($codes->text($sealedTo ?? $code)));

    return UploadedFile::fake()->createWithContent('acme.coresmith-move', $file);
}

describe('on coresmith cloud', function () {
    beforeEach(fn() => CloudBilling::enable());

    test('an owner pastes a move code and the team is paused with its file ready', function () {
        $team = moveHttpCloudTeam();

        $this->actingAs(moveHttpOwner($team))->from(route('settings.billing'))
            ->post(route('settings.billing.move.store'), ['code' => moveHttpCodeText()])
            ->assertRedirect(route('settings.billing'))
            ->assertSessionHas('success', 'Saved. Coresmith Cloud has paused this team. Upload the move file on your panel to finish.');

        expect($team->fresh()->moving_at)->not->toBeNull();
    });

    test('a missing code is asked for', function () {
        $team = moveHttpCloudTeam();

        $this->actingAs(moveHttpOwner($team))->post(route('settings.billing.move.store'), ['code' => ''])
            ->assertSessionHasErrors(['code' => 'Paste the move code from Settings, General on your panel.']);
    });

    test('only someone who manages billing can move the team', function () {
        $team = moveHttpCloudTeam();

        $this->actingAs(CloudBilling::member($team))->post(route('settings.billing.move.store'), ['code' => moveHttpCodeText()])->assertForbidden();

        expect($team->fresh()->moving_at)->toBeNull();
    });

    test('the move file downloads with the team address in its name', function () {
        $team = moveHttpCloudTeam();
        app(TeamMove::class)->start($team, moveHttpOwner($team), moveHttpCodeText());

        $response = $this->actingAs(moveHttpOwner($team))->get(route('settings.billing.move.file'))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="acme.coresmith-move"');

        expect($response->getContent())->toBe($team->fresh()->move_file);
    });

    test('there is no file to download before a move starts', function () {
        $team = moveHttpCloudTeam();

        $this->actingAs(moveHttpOwner($team))->get(route('settings.billing.move.file'))->assertNotFound();
    });

    test('a moving team refuses changes until the move is cancelled', function () {
        $team = moveHttpCloudTeam();
        $server = $team->servers()->first();
        app(TeamMove::class)->start($team, moveHttpOwner($team), moveHttpCodeText());

        $this->actingAs(moveHttpOwner($team))->from(route('servers.show', $server))->post(route('servers.audit', $server))
            ->assertSessionHas('error', 'This team is moving to your own panel, so Coresmith Cloud no longer changes it. Cancel the move in Billing settings to make changes here again.');

        $this->actingAs(moveHttpOwner($team))->from(route('settings.billing'))->delete(route('settings.billing.move.destroy'))
            ->assertRedirect(route('settings.billing'))
            ->assertSessionHas('success', 'Saved. Coresmith Cloud manages this team again.');

        expect($team->fresh()->moving_at)->toBeNull()
            ->and($server->fresh()->status)->toBe(ServerStatus::Active);
    });

    test('the billing page and every other page say the team is moving', function () {
        config(['app.url' => 'https://app.coresmith.io']);
        $team = moveHttpCloudTeam();
        app(TeamMove::class)->start($team, moveHttpOwner($team), moveHttpCodeText());

        $this->actingAs(moveHttpOwner($team))->get(route('settings.billing'))->assertInertia(fn(Assert $page) => $page
            ->where('move.to', 'https://app.coresmith.io')
            ->where('move.file', route('settings.billing.move.file'))
            ->where('move.can_cancel', true));

        $this->actingAs(moveHttpOwner($team))->get(route('dashboard'))->assertInertia(fn(Assert $page) => $page
            ->where('billing.banner.title', 'This team is moving to your own panel.')
            ->where('billing.banner.body', 'Coresmith Cloud has stopped managing its servers, and they keep running. Upload the move file on https://app.coresmith.io to finish.')
            ->where('billing.banner.action', 'See the move'));
    });

    test('coresmith cloud does not make move codes', function () {
        $team = moveHttpCloudTeam();

        $this->actingAs(moveHttpOwner($team))->post(route('settings.moves.store'))->assertNotFound();
    });
});

describe('on a self-hosted panel', function () {
    beforeEach(function () {
        Queue::fake([TakeOverServer::class]);
        Notification::fake();
    });

    test('an owner of the panel makes a move code and sees it in general settings', function () {
        $owner = moveHttpPanelOwner();

        $this->actingAs($owner)->from(route('settings.general'))->post(route('settings.moves.store'))
            ->assertRedirect(route('settings.general'))
            ->assertSessionHas('success', 'Saved. Paste the move code into Coresmith Cloud.');

        $this->actingAs($owner)->get(route('settings.general'))->assertInertia(fn(Assert $page) => $page
            ->where('moves.code.text', fn(string $text): bool => str_starts_with($text, 'csmove1_'))
            ->where('moves.code.preview', null));
    });

    test('a new code replaces the one before it', function () {
        $owner = moveHttpPanelOwner();

        $this->actingAs($owner)->post(route('settings.moves.store'));
        $this->actingAs($owner)->post(route('settings.moves.store'));

        expect(MoveCode::query()->where('user_id', $owner->id)->count())->toBe(1);
    });

    test('uploading a move file shows what will move', function () {
        $owner = moveHttpPanelOwner();
        $code = app(MoveCodes::class)->create($owner);

        $this->actingAs($owner)->from(route('settings.general'))->post(route('settings.moves.upload', $code), ['file' => moveHttpUploaded($code)])
            ->assertRedirect(route('settings.general'));

        $this->actingAs($owner)->get(route('settings.general'))->assertInertia(fn(Assert $page) => $page
            ->where('moves.code.preview.team', 'Acme')
            ->where('moves.code.preview.servers', ['web-01'])
            ->where('moves.code.preview.applications', 1));
    });

    test('a file sealed to another code is refused', function () {
        $owner = moveHttpPanelOwner();
        $code = app(MoveCodes::class)->create($owner);
        $other = app(MoveCodes::class)->create(User::factory()->create());

        $this->actingAs($owner)->post(route('settings.moves.upload', $code), ['file' => moveHttpUploaded($code, $other)])
            ->assertSessionHasErrors(['file' => "This move file was made for a different move code. Paste this panel's code in Coresmith Cloud and download the file again."]);

        expect($code->fresh()->file)->toBeNull();
    });

    test('moving the team brings it here and starts taking its servers over', function () {
        $owner = moveHttpPanelOwner();
        $code = app(MoveCodes::class)->create($owner);
        $this->actingAs($owner)->post(route('settings.moves.upload', $code), ['file' => moveHttpUploaded($code)]);

        $response = $this->actingAs($owner)->post(route('settings.moves.import', $code));
        $team = Team::query()->where('name', 'Acme')->firstOrFail();

        $response->assertRedirect(app(TeamUrls::class)->home($team))
            ->assertSessionHas('success', 'Moved. Acme is here, and its servers are being taken over.');

        expect($team->servers()->first()->status)->toBe(ServerStatus::Moving);
        Queue::assertPushed(TakeOverServer::class, 1);
    });

    test('moving needs the file first', function () {
        $owner = moveHttpPanelOwner();
        $code = app(MoveCodes::class)->create($owner);

        $this->actingAs($owner)->post(route('settings.moves.import', $code))->assertSessionHasErrors(['file' => 'Upload the move file first.']);
    });

    test('someone who does not own a team here cannot make a move code', function () {
        $member = User::factory()->create();
        Team::factory()->owned()->create()->members()->attach($member, ['role' => TeamRole::Admin->value]);

        $this->actingAs($member)->post(route('settings.moves.store'))->assertForbidden();
    });

    test("another person's move code is not found", function () {
        $code = app(MoveCodes::class)->create(moveHttpPanelOwner());

        $this->actingAs(moveHttpPanelOwner())->post(route('settings.moves.import', $code))->assertNotFound();
    });

    test('a move code can be removed', function () {
        $owner = moveHttpPanelOwner();
        $code = app(MoveCodes::class)->create($owner);

        $this->actingAs($owner)->from(route('settings.general'))->delete(route('settings.moves.destroy', $code))
            ->assertRedirect(route('settings.general'))
            ->assertSessionHas('success', 'Removed.');

        expect(MoveCode::query()->find($code->id))->toBeNull();
    });

    test('a failed takeover can be tried again', function () {
        $owner = moveHttpPanelOwner();
        $server = Server::factory()->create(['team_id' => $owner->current_team_id, 'status' => ServerStatus::Moving, 'takeover' => ['state' => 'failed', 'error' => 'unreachable', 'attempts' => 1, 'cloud_key' => 'ssh-ed25519 AAAA']]);

        $this->actingAs($owner)->from(route('servers.show', $server))->post(route('servers.takeover.store', $server))
            ->assertRedirect(route('servers.show', $server))
            ->assertSessionHas('success', "Trying {$server->name} again.");

        expect($server->fresh()->takeover)->toMatchArray(['state' => 'waiting', 'error' => null]);
        Queue::assertPushed(TakeOverServer::class, 1);
    });

    test('a takeover that has not failed cannot be retried', function () {
        $owner = moveHttpPanelOwner();
        $server = Server::factory()->create(['team_id' => $owner->current_team_id, 'status' => ServerStatus::Moving, 'takeover' => ['state' => 'running', 'attempts' => 1]]);

        $this->actingAs($owner)->post(route('servers.takeover.store', $server))->assertNotFound();
    });

    test('a self-hosted panel has no move file to download', function () {
        $this->actingAs(moveHttpPanelOwner())->get(route('settings.billing.move.file'))->assertNotFound();
    });
});
