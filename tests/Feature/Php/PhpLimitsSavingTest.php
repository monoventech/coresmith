<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\ApplyPhpLimits;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ActivityFeed;
use App\Services\Php\PhpLimits;
use App\Services\Process\ProcessManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function phpSavingApp(array $attributes = []): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active, 'host_key_fingerprint' => null]);

    return Application::factory()->for($team)->for($server)->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'app_size' => 'small',
        'power_state' => PowerState::Active,
        'current_release' => '20261115-080000',
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
        ...$attributes,
    ]);
}

function phpSavingMember(Application $application, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create();
    $application->team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function phpSavingSend(Application $application, array $values, TeamRole $role = TeamRole::Owner)
{
    return test()->actingAs(phpSavingMember($application, $role))
        ->from(route('servers.applications.show', [$application->server_id, $application]))
        ->put(route('servers.applications.php-limits.update', [$application->server_id, $application]), $values);
}

test('an owner saves new PHP limits, which are kept, logged and applied without a deploy', function () {
    Queue::fake();
    $application = phpSavingApp();

    phpSavingSend($application, ['uploads' => 128, 'memory' => 256, 'seconds' => 120, 'reload' => 'timestamps'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Saved. PHP on web-1 picks these up in a few seconds, without a deploy.');

    expect($application->fresh()->php_limits)->toBe(['uploads' => 128, 'memory' => 256, 'seconds' => 120, 'reload' => 'timestamps'])
        ->and(Activity::query()->where('description', 'php_limits_changed')->sole()->properties->toArray())->toBe([
            'from' => ['uploads' => 64, 'memory' => 128, 'seconds' => 60, 'reload' => 'deploy'],
            'to' => ['uploads' => 128, 'memory' => 256, 'seconds' => 120, 'reload' => 'timestamps'],
        ]);
    Queue::assertPushed(ApplyPhpLimits::class, 1);
});

test('each limit takes only its own choices', function (string $field, mixed $value) {
    Queue::fake();
    $application = phpSavingApp();

    phpSavingSend($application, [...['uploads' => 64, 'memory' => 128, 'seconds' => 60, 'reload' => 'deploy'], $field => $value])
        ->assertSessionHasErrors($field);

    expect($application->fresh()->php_limits)->toBeNull();
    Queue::assertNothingPushed();
})->with([
    'uploads off the list' => ['uploads', 7],
    'time off the list' => ['seconds', 45],
    'an unknown reload' => ['reload', 'never'],
    'no memory' => ['memory', null],
]);

test('memory past what the app size allows says which size gives more', function () {
    Queue::fake();
    $application = phpSavingApp(['app_size' => 'small']);

    phpSavingSend($application, ['uploads' => 64, 'memory' => 512, 'seconds' => 60, 'reload' => 'deploy'])
        ->assertSessionHasErrors(['memory' => 'The Small size allows up to 256 MB per process. Pick a larger size for more.']);
});

test('saving the limits already in place changes nothing', function () {
    Queue::fake();
    $application = phpSavingApp();

    phpSavingSend($application, ['uploads' => 64, 'memory' => 128, 'seconds' => 60, 'reload' => 'deploy'])->assertRedirect();

    expect(Activity::query()->where('description', 'php_limits_changed')->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('a member sees the limits but cannot change them', function () {
    Queue::fake();

    phpSavingSend(phpSavingApp(), ['uploads' => 128, 'memory' => 128, 'seconds' => 60, 'reload' => 'deploy'], TeamRole::Member)->assertForbidden();
});

test('an app that does not run PHP has no PHP limits', function () {
    Queue::fake();

    phpSavingSend(phpSavingApp(['type' => ApplicationType::Js, 'framework' => null]), ['uploads' => 128, 'memory' => 128, 'seconds' => 60, 'reload' => 'deploy'])->assertNotFound();
});

test('applying writes the file, reloads PHP-FPM gracefully and restarts the workers', function () {
    $application = phpSavingApp(['php_limits' => ['uploads' => 256, 'memory' => 256, 'seconds' => 60, 'reload' => 'deploy']]);
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new ApplyPhpLimits($application))->handle(app(SSHConnectionManager::class), app(ProcessManager::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($transport->uploads()['/etc/coresmith-php/shop/coresmith.ini'])->toContain("upload_max_filesize = 256M\n")
        ->and($transport->commands())->toContain("systemctl reload-or-restart 'coresmith-app-shop-fpm'")
        ->and($transport->commands())->toContain("systemctl restart 'coresmith-app-shop-queue'")
        ->and($application->fresh()->php_written_at)->not->toBeNull();
});

test('a stopped app gets its file, and its processes take it when it starts', function () {
    $application = phpSavingApp(['power_state' => PowerState::Stopped]);
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new ApplyPhpLimits($application))->handle(app(SSHConnectionManager::class), app(ProcessManager::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($transport->uploads())->toHaveKey('/etc/coresmith-php/shop/coresmith.ini')
        ->and(implode("\n", $transport->commands()))->not->toContain('reload-or-restart');
});

test('a write that fails is logged with what the server said', function () {
    $application = phpSavingApp();
    $transport = (new FakeSSHTransport)->respondTo("install -d -m 0755 '/etc/coresmith-php' '/etc/coresmith-php/shop'", 'install: cannot create directory: Read-only file system', 1);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        expect(fn() => (new ApplyPhpLimits($application))->handle(app(SSHConnectionManager::class), app(ProcessManager::class)))->toThrow(RuntimeException::class);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect(Activity::query()->where('description', 'php_limits_failed')->sole()->properties['error'])->toContain('Read-only file system');
});

test('PHP apps whose limits were never written get them, a few at a time, once they have a release on a server Coresmith reaches', function () {
    Queue::fake();
    $due = phpSavingApp();
    $written = phpSavingApp(['slug' => 'written', 'php_written_at' => now()]);
    $node = phpSavingApp(['slug' => 'node', 'type' => ApplicationType::Js, 'framework' => null]);
    $unreleased = phpSavingApp(['slug' => 'unreleased', 'current_release' => null]);
    $unreachable = phpSavingApp(['slug' => 'unreachable']);
    $unreachable->server->forceFill(['connection_failures' => 3])->save();
    $rekeyed = phpSavingApp(['slug' => 'rekeyed']);
    $rekeyed->server->forceFill(['pending_host_key_fingerprint' => 'SHA256:changed'])->save();

    ApplyPhpLimits::catchUp();

    Queue::assertPushed(ApplyPhpLimits::class, 1);
    Queue::assertPushed(ApplyPhpLimits::class, fn(ApplyPhpLimits $job): bool => $job->application->is($due));
});

test('the catch-up queues 20 apps a run, and the next run takes the ones left over without making them wait', function () {
    Queue::fake();
    $application = phpSavingApp();

    foreach (range(1, 20) as $index) {
        Application::factory()->for($application->team)->for($application->server)->create([
            'slug' => "shop-{$index}",
            'type' => ApplicationType::Php,
            'current_release' => '20261115-080000',
        ]);
    }

    ApplyPhpLimits::catchUp();

    Queue::assertPushed(ApplyPhpLimits::class, 20);

    ApplyPhpLimits::catchUp();

    Queue::assertPushed(ApplyPhpLimits::class, 21);
});

test('an app the catch-up already tried waits an hour before the next try, so a server that keeps refusing does not fill the feed', function () {
    Queue::fake();
    phpSavingApp();

    ApplyPhpLimits::catchUp();
    ApplyPhpLimits::catchUp();
    $this->travel(59)->minutes();
    ApplyPhpLimits::catchUp();

    Queue::assertPushed(ApplyPhpLimits::class, 1);

    $this->travel(2)->minutes();
    ApplyPhpLimits::catchUp();

    Queue::assertPushed(ApplyPhpLimits::class, 2);
});

test('the feed says what changed and what went wrong', function () {
    $application = phpSavingApp();
    activity()->performedOn($application)->withProperties(['from' => ['uploads' => 64, 'memory' => 128, 'seconds' => 60, 'reload' => 'deploy'], 'to' => ['uploads' => 128, 'memory' => 256, 'seconds' => 120, 'reload' => 'timestamps']])->log('php_limits_changed');
    activity()->performedOn($application)->withProperties(['error' => 'mkdir: cannot create directory: Read-only file system'])->log('php_limits_failed');

    $rows = collect(app(ActivityFeed::class)->forServer($application->server))->map(fn(array $row): string => collect($row['parts'])->pluck('text')->implode(''));

    expect($rows)->toContain('PHP limits changed on Shop: uploads up to 128 MB, 256 MB of memory, web requests stop after 2 minutes, new code loads every 2 seconds')
        ->and($rows)->toContain("Couldn't apply the PHP limits on Shop, mkdir: cannot create directory: Read-only file system");
});

test('the limits stay the same when nothing is stored, so saving is the first write', function () {
    expect(PhpLimits::for(phpSavingApp())->ini())->toContain("memory_limit = 128M\n");
});
