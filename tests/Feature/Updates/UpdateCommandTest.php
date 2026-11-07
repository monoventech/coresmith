<?php

declare(strict_types=1);

use App\Services\Updates\UpdatePaths;
use App\Services\Updates\UpdateState;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->paths = updatesPanel();
});

afterEach(function () {
    updatesCleanUp();
});

test('the command updates after asking', function () {
    $this->artisan('coresmith:update')
        ->expectsOutput('Current version: v0.1.0')
        ->expectsOutput('Update available: v0.1.1')
        ->expectsConfirmation('Update Coresmith from v0.1.0 to v0.1.1?', 'yes')
        ->expectsOutput('Coresmith updated to v0.1.1.')
        ->assertExitCode(0);
});

test('declining changes nothing', function () {
    $this->artisan('coresmith:update')
        ->expectsConfirmation('Update Coresmith from v0.1.0 to v0.1.1?', 'no')
        ->assertExitCode(0);

    expect(app(UpdateState::class)->status())->toBeNull();
});

test('force skips the question', function () {
    $this->artisan('coresmith:update', ['--force' => true])
        ->doesntExpectOutput('Update Coresmith from v0.1.0 to v0.1.1?')
        ->expectsOutput('Coresmith updated to v0.1.1.')
        ->assertExitCode(0);
});

test('a named version is installed without the feed\'s say', function () {
    updatesFeed('nope', 500);

    $this->artisan('coresmith:update', ['--to' => '0.1.1', '--force' => true])
        ->expectsOutput('Coresmith updated to v0.1.1.')
        ->assertExitCode(0);
});

test('an up to date panel says so', function () {
    updatesFeed(['latest' => '0.1.0']);

    $this->artisan('coresmith:update')
        ->expectsOutput("You're on the latest version, v0.1.0.")
        ->assertExitCode(0);
});

test('a refusal is printed and fails', function () {
    config(['coresmith.updates.enabled' => false]);

    $this->artisan('coresmith:update')
        ->expectsOutput('Self-updates are off on this install. Installs made by the Coresmith installer turn them on with CORESMITH_UPDATES_ENABLED=true.')
        ->assertExitCode(1);
});

test('a rolled back update fails with the log and where to report it', function () {
    updatesMachine(['artisan:migrate' => [1, "SQLSTATE[23000]: Integrity constraint violation\n"]]);

    $this->artisan('coresmith:update', ['--force' => true])
        ->expectsOutput('Running migrations failed: SQLSTATE[23000]: Integrity constraint violation. Coresmith rolled back to v0.1.0, and the panel is running as before.')
        ->expectsOutputToContain('Log: ' . app(UpdatePaths::class)->runs() . '/')
        ->expectsOutput('Report it at https://github.com/monoventech/coresmith/issues if it keeps happening.')
        ->assertExitCode(1);
});

test('recover with nothing to recover says so', function () {
    $this->artisan('coresmith:update', ['--recover' => true])
        ->expectsOutput('Nothing to recover.')
        ->assertExitCode(0);
});

test('step lines stream to the terminal', function () {
    $this->artisan('coresmith:update', ['--force' => true])
        ->expectsOutput('Installing PHP dependencies')
        ->expectsOutput('Checking the panel answers')
        ->assertExitCode(0);
});
