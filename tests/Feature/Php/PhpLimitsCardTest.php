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
use Illuminate\Testing\TestResponse;

function phpCardApp(array $attributes = []): Application
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
        ...$attributes,
    ]);
}

function phpCardPage(Application $application): TestResponse
{
    $viewer = User::factory()->create();
    $application->team->members()->attach($viewer, ['role' => TeamRole::Member->value]);

    return test()->actingAs($viewer->fresh())->get(route('servers.applications.show', [$application->server_id, $application]));
}

test('the app page shows a PHP app its limits and the choices its size allows', function () {
    $application = phpCardApp(['app_size' => 'medium']);

    phpCardPage($application)->assertInertia(fn($page) => $page
        ->where('php.cells', [
            ['label' => 'Uploads up to', 'value' => '64 MB per request'],
            ['label' => 'Memory', 'value' => '256 MB per process'],
            ['label' => 'Web requests stop after', 'value' => '60 seconds'],
            ['label' => 'New code loads', 'value' => 'At each deploy'],
        ])
        ->where('php.values', ['uploads' => 64, 'memory' => 256, 'seconds' => 60, 'reload' => 'deploy'])
        ->where('php.choices.memory', [['value' => 64, 'label' => '64 MB'], ['value' => 128, 'label' => '128 MB'], ['value' => 256, 'label' => '256 MB'], ['value' => 512, 'label' => '512 MB']])
        ->where('php.choices.uploads.7', ['value' => 1024, 'label' => '1 GB per request'])
        ->where('php.choices.seconds', [['value' => 30, 'label' => '30 seconds'], ['value' => 60, 'label' => '60 seconds'], ['value' => 120, 'label' => '2 minutes'], ['value' => 300, 'label' => '5 minutes']])
        ->where('php.size', 'Medium')
        ->where('php.cap', '512 MB')
        ->where('php.held', false)
        ->where('php.failure', null));
});

test('an app that does not run PHP gets no PHP card', function () {
    phpCardPage(phpCardApp(['type' => ApplicationType::Js, 'framework' => null]))->assertInertia(fn($page) => $page->where('php', null));
});

test('a change that could not be applied shows on the card until the next write', function () {
    $application = phpCardApp(['php_written_at' => now()->subHour()]);
    activity()->performedOn($application)->withProperties(['error' => 'web-1 took too long to answer. Try again in a minute.'])->log(ApplyPhpLimits::FAILED);

    phpCardPage($application)->assertInertia(fn($page) => $page->where('php.failure', 'web-1 took too long to answer. Try again in a minute.'));

    $application->forceFill(['php_written_at' => now()->addMinute()])->save();

    phpCardPage($application)->assertInertia(fn($page) => $page->where('php.failure', null));
});
