<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Server\ThisMachine;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

function removalFolder(): string
{
    $folder = sys_get_temp_dir() . '/coresmith-uninstall-' . bin2hex(random_bytes(4));
    test()->scratchFolders = [...test()->scratchFolders, $folder, "{$folder}-answers"];
    File::ensureDirectoryExists($folder);
    File::ensureDirectoryExists("{$folder}-answers");
    config(['coresmith.removal.state_path' => $folder, 'coresmith.removal.answer_path' => "{$folder}-answers"]);

    return $folder;
}

function removalHelper(string $folder, bool $answers = true): void
{
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(function (CarbonInterval $duration) use ($folder, $answers): void {
        $request = @file_get_contents("{$folder}/request");

        if ($request === false || !$answers) {
            return;
        }

        [$id, $what] = explode(' ', trim($request), 2);

        if ($what === 'ping') {
            unlink("{$folder}/request");
            file_put_contents("{$folder}-answers/result.json", json_encode(['id' => $id, 'ok' => true]));
        }
    });
}

function removalOwner(array $attributes = []): User
{
    $owner = User::factory()->create($attributes);
    $team = Team::factory()->create(['name' => 'Monoven', 'owner_id' => $owner->id]);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $owner->forceFill(['current_team_id' => $team->id])->save();

    return $owner->fresh();
}

function removalAsk(User $user, array $payload = [])
{
    return test()->actingAs($user)
        ->from(route('settings.general'))
        ->post(route('settings.removal.store'), ['address' => 'panel.example.test', 'password' => 'password', ...$payload]);
}

beforeEach(function () {
    $this->scratchFolders = [];
    config(['coresmith.edition' => 'self-hosted', 'app.url' => 'https://panel.example.test']);
});

afterEach(function () {
    foreach ($this->scratchFolders as $folder) {
        File::deleteDirectory($folder);
    }
});

test('an owner removes Coresmith with its address and their password, once its helper answers', function () {
    $folder = removalFolder();
    removalHelper($folder);
    $owner = removalOwner();

    removalAsk($owner)->assertRedirect(route('settings.removal.show'));

    expect((string) file_get_contents("{$folder}/request"))->toMatch('/^[A-Za-z0-9]{32} uninstall\n$/')
        ->and(Activity::query()->where('description', 'coresmith_removal_started')->first()?->causer_id)->toBe($owner->id);
});

test('an owner with two-factor on gives a code too, and a recovery code works once', function () {
    $folder = removalFolder();
    removalHelper($folder);
    $google = new Google2FA;
    $secret = $google->generateSecretKey();
    $owner = removalOwner(['two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode(['recover-one', 'recover-two'])), 'two_factor_confirmed_at' => now()]);

    removalAsk($owner)->assertSessionHasErrors(['code' => 'Enter the code from your authenticator app.']);
    removalAsk($owner, ['code' => '000000'])->assertSessionHasErrors(['code' => "That code isn't right."]);

    expect(file_exists("{$folder}/request"))->toBeFalse();

    removalAsk($owner, ['code' => $google->getCurrentOtp($secret)])->assertRedirect(route('settings.removal.show'));

    unlink("{$folder}/request");
    removalAsk($owner->fresh(), ['code' => 'recover-one'])->assertRedirect(route('settings.removal.show'));

    expect($owner->fresh()->recoveryCodes())->not->toContain('recover-one');
});

test('a wrong address or password is refused next to its field', function (array $payload, array $errors) {
    removalHelper(removalFolder());

    removalAsk(removalOwner(), $payload)->assertSessionHasErrors($errors);
})->with([
    'the wrong address' => [['address' => 'panel.example.tes'], ['address' => 'Type panel.example.test to confirm.']],
    'no address' => [['address' => ''], ['address' => 'Type panel.example.test to confirm.']],
    'the wrong password' => [['password' => 'nope'], ['password' => "That password isn't right."]],
]);

test('Cloud never offers it, and never takes a request for it', function () {
    $folder = removalFolder();
    removalHelper($folder);
    config(['coresmith.edition' => 'cloud']);
    $owner = removalOwner();

    expect(removalAsk($owner)->headers->get('Location'))->not->toBe(route('settings.removal.show'))
        ->and(file_exists("{$folder}/request"))->toBeFalse()
        ->and($this->actingAs($owner)->get(route('settings.removal.show'))->status())->not->toBe(200)
        ->and($this->actingAs($owner)->getJson(route('settings.removal.status'))->status())->not->toBe(200);

    $this->actingAs($owner)->get(route('settings.general'))->assertInertia(fn($page) => $page->where('removal', null));
});

test('only people who manage the install can remove it', function () {
    removalHelper(removalFolder());
    $owner = removalOwner();
    $admin = User::factory()->create();
    $owner->teams()->first()->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    removalAsk($admin)->assertForbidden();
    $this->actingAs($admin)->get(route('settings.general'))->assertInertia(fn($page) => $page->where('removal', null));
});

test('removal waits for deploys, provisioning and cloud work', function (Closure $busy, string $refusal) {
    $folder = removalFolder();
    removalHelper($folder);
    $owner = removalOwner();
    $busy($owner->teams()->first());

    removalAsk($owner)->assertSessionHasErrors(['removal' => $refusal]);

    expect(file_exists("{$folder}/request"))->toBeFalse();
})->with([
    'a deploy' => [function (Team $team): void {
        $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['name' => 'atlas']);
        Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running]);
    }, 'A deploy is running for atlas. Wait for it to finish, then try again.'],
    'provisioning' => [function (Team $team): void {
        Server::factory()->for($team)->create(['name' => 'anvil-02', 'status' => ServerStatus::Provisioning]);
    }, 'anvil-02 is still provisioning. Wait for it to finish, then try again.'],
]);

test('without the helper, the owner is told to run the installer once', function () {
    config(['coresmith.removal.state_path' => sys_get_temp_dir() . '/coresmith-uninstall-missing-' . bin2hex(random_bytes(4))]);

    removalAsk(removalOwner())->assertSessionHasErrors(['removal' => "Coresmith's removal helper isn't installed. Run the installer once, then try again: " . ThisMachine::INSTALLER]);
});

test('a helper that does not answer is named', function () {
    $folder = removalFolder();
    removalHelper($folder, answers: false);

    removalAsk(removalOwner())->assertSessionHasErrors(['removal' => "Coresmith's removal helper didn't answer. Check it with journalctl -u coresmith-uninstall, then try again."]);

    expect(file_exists("{$folder}/request"))->toBeFalse();
});

test('a second removal while one runs is refused', function () {
    $folder = removalFolder();
    removalHelper($folder);
    file_put_contents("{$folder}-answers/status.json", json_encode(['id' => 'abc', 'stage' => 'releasing', 'saved' => '/root/coresmith-uninstalled-20261111-1334']));

    removalAsk(removalOwner())->assertSessionHasErrors(['removal' => 'Coresmith is already being removed.']);
});

test('status reports the stage, the saved copy and the servers Coresmith could not reach', function () {
    $folder = removalFolder();
    file_put_contents("{$folder}-answers/status.json", json_encode(['id' => 'abc', 'stage' => 'stopping', 'saved' => '/root/coresmith-uninstalled-20261111-1334']));
    file_put_contents("{$folder}/released.json", json_encode(['removed' => ['anvil-01'], 'failed' => [['name' => 'outpost-02', 'file' => '/root/.ssh/authorized_keys', 'comment' => 'coresmith@outpost-02']]]));

    $this->actingAs(removalOwner())
        ->getJson(route('settings.removal.status'))
        ->assertOk()
        ->assertExactJson([
            'stage' => 'stopping',
            'saved' => '/root/coresmith-uninstalled-20261111-1334',
            'removed' => ['anvil-01'],
            'failed' => [['name' => 'outpost-02', 'file' => '/root/.ssh/authorized_keys', 'comment' => 'coresmith@outpost-02']],
        ]);
});

test('status before anything started says so', function () {
    removalFolder();

    $this->actingAs(removalOwner())
        ->getJson(route('settings.removal.status'))
        ->assertExactJson(['stage' => null, 'saved' => null, 'removed' => [], 'failed' => []]);
});

test('the progress page is the owner’s once removal starts', function () {
    $folder = removalFolder();
    file_put_contents("{$folder}-answers/status.json", json_encode(['id' => 'abc', 'stage' => 'saving', 'saved' => '']));

    $this->actingAs(removalOwner())
        ->get(route('settings.removal.show'))
        ->assertInertia(fn($page) => $page->component('settings/Removing')->where('address', 'panel.example.test'));
});

test('too many tries are slowed down', function () {
    removalHelper(removalFolder());
    $owner = removalOwner();

    foreach (range(1, 5) as $try) {
        removalAsk($owner, ['password' => 'nope']);
    }

    removalAsk($owner, ['password' => 'nope'])->assertTooManyRequests();
});

test('General carries what the removal dialog needs, with real numbers', function () {
    $folder = removalFolder();
    $owner = removalOwner();
    $team = $owner->teams()->first();
    Server::factory()->for($team)->count(2)->create();
    $machine = Server::factory()->for($team)->create(['runs_coresmith' => true]);
    Application::factory()->for($team)->for($machine)->create();
    $acme = Team::factory()->create(['name' => 'Acme']);
    Server::factory()->for($acme)->create();

    $driver = (string) config('database.connections.' . config('database.default') . '.driver');

    $this->actingAs($owner)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('removal', [
            'address' => 'panel.example.test',
            'servers' => 4,
            'other_teams' => ['Acme'],
            'this_machine_apps' => true,
            'database' => $driver,
            'database_name' => $driver === 'sqlite' ? null : (string) config('database.connections.' . config('database.default') . '.database'),
            'two_factor' => false,
            'helper' => true,
            'installer' => ThisMachine::INSTALLER,
        ]));
});
