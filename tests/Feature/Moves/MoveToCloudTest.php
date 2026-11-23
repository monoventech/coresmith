<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\TakeOverServer;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\MoveCode;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\MoveFile;
use App\Services\Moves\TeamImporter;
use App\Services\Network\LocalAddresses;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;
use Tests\Support\MovePayloads;

function moveToCloudTeam(?Plan $plan = null): Team
{
    $factory = $plan === null ? Team::factory()->onTrial() : Team::factory()->subscribed($plan);

    return $factory->owned()->create(['name' => "Ada's team"]);
}

function moveToCloudOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

/**
 * @param  array<string, mixed>  $payload
 */
function moveToCloudCode(User $owner, Team $team, array $payload): MoveCode
{
    $codes = app(MoveCodes::class);
    $code = $codes->create($owner, $team);
    $code->forceFill(['file' => app(MoveFile::class)->seal($payload, $codes->read($codes->text($code)))])->save();

    return $code;
}

/**
 * @param  array<string, mixed>  $payload
 */
function moveToCloudFile(MoveCode $code, array $payload): UploadedFile
{
    $codes = app(MoveCodes::class);

    return UploadedFile::fake()->createWithContent('arrivals.coresmith-move', app(MoveFile::class)->seal($payload, $codes->read($codes->text($code))));
}

beforeEach(function () {
    CloudBilling::enable();
    Queue::fake();
    Notification::fake();
    FakeStripe::fake([FakeStripe::url('subscriptions/*') => fn() => Http::response(FakeStripe::subscription('sub_live', 'cus_live', 'price_test_pro'))]);
    app()->instance(LocalAddresses::class, new class implements LocalAddresses
    {
        public function all(): array
        {
            return ['127.0.0.1', '::1', '203.0.113.10'];
        }
    });
});

test('an owner of an empty trial team brings a self-hosted team into it', function () {
    $team = moveToCloudTeam();
    $owner = moveToCloudOwner($team);
    $teams = Team::query()->count();

    $this->actingAs($owner)->post(route('settings.moves.store'))
        ->assertSessionHas('success', 'Saved. Paste the move code into your self-hosted Coresmith.');

    $code = MoveCode::query()->where('user_id', $owner->id)->sole();

    $this->post(route('settings.moves.upload', $code), ['file' => moveToCloudFile($code, MovePayloads::selfHosted())])->assertSessionHasNoErrors();
    $this->post(route('settings.moves.import', $code))->assertSessionHas('success', 'Moved. Coresmith Cloud is taking over 1 server.');

    $fresh = $team->fresh();

    expect($code->fresh()->team_id)->toBe($team->id)
        ->and(Team::query()->count())->toBe($teams)
        ->and($fresh->name)->toBe("Ada's team")
        ->and($fresh->servers->map(fn(Server $server): ServerStatus => $server->status)->all())->toBe([ServerStatus::Moving])
        ->and($fresh->applications()->pluck('slug')->all())->toBe(['storefront'])
        ->and(ManagedDatabase::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and(SourceProvider::query()->where('team_id', $team->id)->value('name'))->toBe('Self-hosted Coresmith');

    Queue::assertPushed(TakeOverServer::class, 1);
});

test('a team that already has servers can neither make a code nor take a move made before they arrived', function () {
    $team = moveToCloudTeam(Plan::Pro);
    $owner = moveToCloudOwner($team);
    $code = moveToCloudCode($owner, $team, MovePayloads::selfHosted());
    Server::factory()->for($team)->create();

    $this->actingAs($owner)->post(route('settings.moves.store'))->assertForbidden();
    $this->post(route('settings.moves.import', $code))
        ->assertSessionHasErrors(['file' => 'This team already has servers. Remove them before you move a team in.']);

    expect($team->servers()->count())->toBe(1);
});

test('the trial takes one server, and a bigger team waits for a plan that fits', function () {
    $team = moveToCloudTeam();
    $owner = moveToCloudOwner($team);
    $payload = MovePayloads::selfHosted(2);
    $code = moveToCloudCode($owner, $team, $payload);
    $refusal = 'Arrivals has 2 servers, and the trial includes 1. Choose a plan that fits in Billing, then move the team.';

    expect(app(TeamImporter::class)->preview($payload, $team, $owner)['plan'])->toBe($refusal);

    $this->actingAs($owner)->post(route('settings.moves.import', $code))->assertSessionHasErrors(['file' => $refusal]);

    expect($team->servers()->count())->toBe(0);
});

test('Starter is for one person, so a team with a teammate waits for Pro or Business', function () {
    $team = moveToCloudTeam(Plan::Starter);
    $owner = moveToCloudOwner($team);
    $payload = MovePayloads::selfHosted(1, ['lin@acme.test']);

    expect(app(TeamImporter::class)->preview($payload, $team, $owner)['plan'])
        ->toBe('Arrivals has 2 people, and Starter is for one person. Choose Pro or Business in Billing, then move the team.');
});

test('Pro takes the whole team, teammates and all', function () {
    $team = moveToCloudTeam(Plan::Pro);
    $owner = moveToCloudOwner($team);
    $payload = MovePayloads::selfHosted(2, ['lin@acme.test']);
    $code = moveToCloudCode($owner, $team, $payload);

    expect(app(TeamImporter::class)->preview($payload, $team, $owner)['plan'])->toBeNull();

    $this->actingAs($owner)->post(route('settings.moves.import', $code))->assertSessionHas('success', 'Moved. Coresmith Cloud is taking over 2 servers.');

    expect($team->servers()->count())->toBe(2)
        ->and($team->invitations()->pluck('email')->all())->toBe(['lin@acme.test']);
});

test('problems in the file show in the preview and stop the move', function () {
    $team = moveToCloudTeam(Plan::Pro);
    $owner = moveToCloudOwner($team);
    $payload = MovePayloads::selfHosted();
    $payload['servers'][0]['ip_address'] = '10.0.0.5';
    $code = moveToCloudCode($owner, $team, $payload);
    $problem = "web-01 is at 10.0.0.5, which Coresmith Cloud can't reach. Give it a public address before you move the team.";

    expect(app(TeamImporter::class)->preview($payload, $team, $owner)['problems'])->toBe([$problem]);

    $this->actingAs($owner)->post(route('settings.moves.import', $code))->assertSessionHasErrors(['file' => $problem]);

    expect($team->servers()->count())->toBe(0);
});

test("a teammate who doesn't manage billing can neither make a code nor move a team in", function () {
    $team = moveToCloudTeam(Plan::Pro);
    $code = moveToCloudCode(moveToCloudOwner($team), $team, MovePayloads::selfHosted());
    $member = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)->post(route('settings.moves.store'))->assertForbidden();
    $this->post(route('settings.moves.import', $code))->assertForbidden();
});

test("a team's own S3 settings stay when a move brings others", function () {
    $team = moveToCloudTeam(Plan::Pro);
    $owner = moveToCloudOwner($team);
    BackupDestination::factory()->create(['team_id' => $team->id, 'bucket' => 'ada-backups']);
    $code = moveToCloudCode($owner, $team, MovePayloads::selfHosted());

    $this->actingAs($owner)->post(route('settings.moves.import', $code))->assertSessionHas('success', 'Moved. Coresmith Cloud is taking over 1 server.');

    expect($team->servers()->count())->toBe(1)
        ->and(BackupDestination::query()->where('team_id', $team->id)->pluck('bucket')->all())->toBe(['ada-backups']);
});
