<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Jobs\CopyCoresmithBackup;
use App\Models\BackupDestination;
use App\Models\Team;
use App\Models\User;
use App\Services\Database\OffsiteBackups;
use App\Services\Panel\BackupCopy;
use App\Services\Panel\PanelBackup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CloudBilling;

function coresmithCopyUser(TeamRole $role = TeamRole::Owner): User
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function coresmithCopyForm(array $overrides = []): array
{
    return [
        'endpoint' => null,
        'region' => 'eu-central-1',
        'bucket' => 'coresmith-backups',
        'prefix' => 'beelink',
        'access_key_id' => 'AKIACOPY000000000000',
        'secret_access_key' => 'a-copy-secret-access-key',
        'path_style' => false,
        ...$overrides,
    ];
}

function coresmithCopyDestination(): BackupDestination
{
    return BackupDestination::query()->create([
        'team_id' => null,
        'region' => 'eu-central-1',
        'bucket' => 'coresmith-backups',
        'prefix' => 'beelink',
        'access_key_id' => 'AKIACOPY000000000000',
        'secret_access_key' => 'a-copy-secret-access-key',
    ]);
}

function coresmithCopyProbe(string $root): void
{
    touch($root . '/panel.sqlite');

    config([
        'database.connections.copy_probe' => ['driver' => 'sqlite', 'database' => $root . '/panel.sqlite', 'prefix' => '', 'foreign_key_constraints' => true],
        'database.default' => 'copy_probe',
    ]);

    DB::connection('copy_probe')->statement('create table probe (note text)');
    DB::connection('copy_probe')->table('probe')->insert(['note' => 'still here']);

    foreach (['2026_05_28_082634_create_activity_log_table', '2026_08_08_120000_create_instance_settings_table'] as $migration) {
        (require base_path("database/migrations/{$migration}.php"))->up();
    }

    Schema::create('backup_destinations', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->ulid('team_id')->nullable()->unique();
        $table->string('endpoint')->nullable();
        $table->string('region', 32);
        $table->string('bucket', 63);
        $table->string('prefix', 200)->nullable();
        $table->text('access_key_id');
        $table->text('secret_access_key');
        $table->boolean('path_style')->default(false);
        $table->string('frequency', 16)->default('daily');
        $table->timestamp('last_run_at')->nullable();
        $table->timestamps();
    });
}

function coresmithCopyEntries(string $gz): array
{
    $path = sys_get_temp_dir() . '/coresmith-copy-' . Str::lower(Str::random(8)) . '.tar.gz';
    file_put_contents($path, $gz);

    try {
        return collect(new RecursiveIteratorIterator(new PharData($path)))->map(fn(PharFileInfo $file): string => $file->getFilename())->sort()->values()->all();
    } finally {
        File::delete($path);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 04:00:00'));
    $this->copyRoot = realpath(sys_get_temp_dir()) . '/coresmith-copy-' . Str::lower(Str::random(10));
    File::ensureDirectoryExists($this->copyRoot . '/app/private');
    $this->previousConnection = config('database.default');
    config(['app.url' => 'https://beelink.example.com']);
});

afterEach(function () {
    config(['database.default' => $this->previousConnection]);
    DB::purge('copy_probe');
    File::deleteDirectory($this->copyRoot);
});

test('someone who manages Coresmith sets where its backups are copied, and the keys stay sealed', function () {
    $this->actingAs(coresmithCopyUser())
        ->from(route('settings.general'))
        ->put(route('settings.backups.copy.update'), coresmithCopyForm())
        ->assertRedirect(route('settings.general'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', "Saved. Each backup is copied to S3 without Coresmith's keys.");

    $this->actingAs(coresmithCopyUser())->put(route('settings.backups.copy.update'), coresmithCopyForm(['access_key_id' => '', 'secret_access_key' => '', 'prefix' => 'beelink-2']))->assertSessionHasNoErrors();

    $destination = BackupDestination::forCoresmith();
    $raw = json_encode(DB::table('backup_destinations')->whereNull('team_id')->first());

    expect(BackupDestination::query()->whereNull('team_id')->count())->toBe(1)
        ->and($destination?->bucket)->toBe('coresmith-backups')
        ->and($destination?->prefix)->toBe('beelink-2')
        ->and($destination?->access_key_id)->toBe('AKIACOPY000000000000')
        ->and($destination?->secret_access_key)->toBe('a-copy-secret-access-key')
        ->and($raw)->not->toContain('a-copy-secret-access-key');
});

test("a copy destination that can't work is refused with what to fix", function () {
    $this->actingAs(coresmithCopyUser())
        ->put(route('settings.backups.copy.update'), coresmithCopyForm(['bucket' => 'Coresmith_Backups']))
        ->assertSessionHasErrors(['bucket' => "That isn't a bucket name. Use lowercase letters, digits, dots and hyphens."]);

    expect(BackupDestination::forCoresmith())->toBeNull();
});

test("people who don't manage Coresmith can't set or remove the copy", function () {
    coresmithCopyDestination();
    $admin = coresmithCopyUser(TeamRole::Admin);

    $this->actingAs($admin)->put(route('settings.backups.copy.update'), coresmithCopyForm())->assertForbidden();
    $this->actingAs($admin)->delete(route('settings.backups.copy.destroy'))->assertForbidden();

    expect(BackupDestination::forCoresmith())->not->toBeNull();
});

test('Coresmith Cloud has no copy of its own backups to set', function () {
    CloudBilling::enable();
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    $this->actingAs(User::query()->findOrFail($team->owner_id))->put(route('settings.backups.copy.update'), coresmithCopyForm())->assertNotFound();

    expect(BackupDestination::forCoresmith())->toBeNull();
});

test('removing the copy leaves the bucket alone and keeps backups on this machine', function () {
    coresmithCopyDestination();
    Http::fake();

    $this->actingAs(coresmithCopyUser())
        ->delete(route('settings.backups.copy.destroy'))
        ->assertSessionHas('success', 'Removed. Backups stay on this machine.');

    expect(BackupDestination::forCoresmith())->toBeNull();
    Http::assertNothingSent();
});

test("a backup is copied with its database only, never Coresmith's keys", function () {
    $this->app->useStoragePath($this->copyRoot);
    coresmithCopyProbe($this->copyRoot);
    coresmithCopyDestination();
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        $sent[] = ['url' => $request->url(), 'method' => $request->method(), 'body' => $request->body()];

        return Http::response('', 200);
    });
    Queue::fake();

    $backup = app(PanelBackup::class)->run();
    (new CopyCoresmithBackup($backup['path']))->handle(app(OffsiteBackups::class));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['method'])->toBe('PUT')
        ->and($sent[0]['url'])->toContain('/beelink/coresmith/beelink.example.com/coresmith-20261120-040000.tar.gz')
        ->and(coresmithCopyEntries($sent[0]['body']))->toBe(['database.sqlite'])
        ->and(BackupCopy::status())->toMatchArray(['ok' => true, 'key' => 'beelink/coresmith/beelink.example.com/coresmith-20261120-040000.tar.gz']);
});

test('a refused copy is recorded with what to check, and the backup stays', function () {
    $this->app->useStoragePath($this->copyRoot);
    coresmithCopyProbe($this->copyRoot);
    coresmithCopyDestination();
    Http::fake(fn() => Http::response('<Error><Code>AccessDenied</Code></Error>', 403));
    Queue::fake();

    $backup = app(PanelBackup::class)->run();
    (new CopyCoresmithBackup($backup['path']))->handle(app(OffsiteBackups::class));

    expect(is_file($backup['path']))->toBeTrue()
        ->and(BackupCopy::status())->toMatchArray(['ok' => false, 'error' => 'The bucket refused the copy: 403. Check the bucket name, region and keys.']);
});

test('a backup queues its copy only when a destination is set', function () {
    $this->app->useStoragePath($this->copyRoot);
    coresmithCopyProbe($this->copyRoot);
    Queue::fake();

    app(PanelBackup::class)->run();
    Queue::assertNotPushed(CopyCoresmithBackup::class);

    coresmithCopyDestination();
    $this->travel(1)->seconds();
    $backup = app(PanelBackup::class)->run();

    Queue::assertPushed(CopyCoresmithBackup::class, fn(CopyCoresmithBackup $job): bool => $job->archive === $backup['path']);
});

test('the Backups card says where copies go, when the last one went, and which keys to keep', function () {
    coresmithCopyDestination();
    BackupCopy::record(true, 'beelink/coresmith/beelink.example.com/coresmith-20261120-040000.tar.gz');

    $this->actingAs(coresmithCopyUser())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('backups.copy.bucket', 'coresmith-backups')
            ->where('backups.copy.prefix', 'beelink')
            ->where('backups.copy.last', 'now')
            ->where('backups.copy.error', null));
});

test('the restore command puts a backup back after asking, then has Coresmith pick up its keys', function () {
    $this->app->useStoragePath($this->copyRoot);
    coresmithCopyProbe($this->copyRoot);
    Queue::fake();
    Process::fake();
    $backup = app(PanelBackup::class)->run();
    DB::connection('copy_probe')->table('probe')->update(['note' => 'changed']);

    $this->artisan('coresmith:restore', ['archive' => $backup['path']])
        ->expectsConfirmation("Replace Coresmith's database with the one in {$backup['path']}?", 'no')
        ->assertFailed();

    expect(DB::connection('copy_probe')->table('probe')->value('note'))->toBe('changed');
    Process::assertNothingRan();

    $this->artisan('coresmith:restore', ['archive' => $backup['path'], '--force' => true])->assertSuccessful();

    DB::purge('copy_probe');
    expect(DB::connection('copy_probe')->table('probe')->value('note'))->toBe('still here');

    foreach (['config:cache', 'horizon:terminate', 'reverb:restart'] as $command) {
        Process::assertRan(fn(PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', $command] && $process->path === base_path());
    }
});

test("the restore command says when a backup can't be read", function () {
    $this->artisan('coresmith:restore', ['archive' => '/nowhere/coresmith-backup.tar.gz', '--force' => true])
        ->expectsOutputToContain("The backup at /nowhere/coresmith-backup.tar.gz can't be read, so it can't be restored.")
        ->assertFailed();
});
