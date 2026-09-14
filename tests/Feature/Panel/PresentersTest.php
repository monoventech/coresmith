<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Panel\DeploymentPresenter;
use Carbon\CarbonImmutable;

test('an application is described by its framework, or its type when it has none', function (?string $framework, ApplicationType $type, string $expected) {
    expect(ApplicationPresenter::kind(new Application(['framework' => $framework, 'type' => $type])))->toBe($expected);
})->with([
    'laravel' => ['laravel', ApplicationType::Php, 'Laravel'],
    'laravel in capitals' => ['LARAVEL', ApplicationType::Php, 'Laravel'],
    'rails' => ['rails', ApplicationType::Ruby, 'Rails'],
    'sveltekit' => ['sveltekit', ApplicationType::Js, 'SvelteKit'],
    'nextjs' => ['nextjs', ApplicationType::Js, 'Next.js'],
    'next' => ['next', ApplicationType::Js, 'Next.js'],
    'an unlisted framework' => ['gatsby', ApplicationType::Js, 'Gatsby'],
    'plain php' => [null, ApplicationType::Php, 'PHP'],
    'plain ruby' => [null, ApplicationType::Ruby, 'Ruby'],
    'static' => [null, ApplicationType::Static, 'Static site'],
    'javascript without a framework' => ['', ApplicationType::Js, 'API'],
]);

test('the runtime names the language and its minor version', function (array $attributes, string $expected) {
    expect(ApplicationPresenter::runtime(new Application($attributes)))->toBe($expected);
})->with([
    'php' => [['type' => ApplicationType::Php, 'php_version' => '8.5.5'], 'PHP 8.5'],
    'php without a version' => [['type' => ApplicationType::Php, 'php_version' => null], 'PHP'],
    'php with an unusual version' => [['type' => ApplicationType::Php, 'php_version' => 'nightly'], 'PHP nightly'],
    'ruby' => [['type' => ApplicationType::Ruby, 'ruby_version' => '3.4.1'], 'Ruby 3.4'],
    'static' => [['type' => ApplicationType::Static], 'Caddy'],
    'node' => [['type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '24.15.0'], 'Node 24.15'],
    'deno' => [['type' => ApplicationType::Js, 'js_runtime' => 'deno'], 'Deno'],
    'bun' => [['type' => ApplicationType::Js, 'js_runtime' => 'bun'], 'Bun'],
    'unspecified javascript runtime' => [['type' => ApplicationType::Js], 'Bun'],
]);

test('the port block spans the ten ports from the base port', function () {
    expect(ApplicationPresenter::portBlock(new Application(['base_port' => 8000])))->toBe('8000–8009');
});

test('the application state follows a fixed precedence', function (array $attributes, string $key, string $label, string $tone) {
    expect(ApplicationPresenter::state(new Application($attributes)))->toBe(['key' => $key, 'label' => $label, 'tone' => $tone]);
})->with([
    'stopped beats deploying' => [['power_state' => PowerState::Stopped, 'status' => ApplicationStatus::Deploying], 'stopped', 'Stopped', 'outline'],
    'deploying beats sleeping' => [['power_state' => PowerState::Sleeping, 'status' => ApplicationStatus::Deploying], 'deploying', 'Deploying', 'accent'],
    'failed beats maintenance' => [['power_state' => PowerState::Active, 'status' => ApplicationStatus::Failed, 'toggles' => ['maintenance_mode' => true]], 'failed', 'Failed', 'destructive'],
    'sleeping beats maintenance' => [['power_state' => PowerState::Sleeping, 'status' => ApplicationStatus::Active, 'toggles' => ['maintenance_mode' => true]], 'sleeping', 'Sleeping', 'default'],
    'maintenance beats live' => [['power_state' => PowerState::Active, 'status' => ApplicationStatus::Active, 'toggles' => ['maintenance_mode' => true]], 'maintenance', 'Maintenance', 'warning'],
    'live' => [['power_state' => PowerState::Active, 'status' => ApplicationStatus::Active, 'toggles' => ['maintenance_mode' => false]], 'live', 'Live', 'success'],
    'never deployed' => [['power_state' => PowerState::Active, 'status' => ApplicationStatus::Pending], 'pending', 'Not deployed', 'default'],
]);

test('a deployment state is derived from its status', function (DeploymentStatus $status, array $expected) {
    expect(DeploymentPresenter::state(new Deployment(['status' => $status, 'log' => ''])))->toBe($expected);
})->with([
    'running' => [DeploymentStatus::Running, ['key' => 'running', 'label' => 'Running', 'tone' => 'accent', 'live' => true]],
    'succeeded' => [DeploymentStatus::Succeeded, ['key' => 'deployed', 'label' => 'Deployed', 'tone' => 'success', 'live' => false]],
    'failed before the swap' => [DeploymentStatus::Failed, ['key' => 'failed', 'label' => 'Failed, no impact', 'tone' => 'destructive', 'live' => false]],
    'cancelled' => [DeploymentStatus::Cancelled, ['key' => 'cancelled', 'label' => 'Cancelled', 'tone' => 'default', 'live' => false]],
    'queued' => [DeploymentStatus::Pending, ['key' => 'queued', 'label' => 'Queued', 'tone' => 'default', 'live' => false]],
]);

test('a deployment that failed after the swap is not described as having no impact', function () {
    $deployment = new Deployment([
        'status' => DeploymentStatus::Failed,
        'log' => "[10:00:00] clone: fetching over https\n[10:00:06] swap: current → releases/20260913-100000\n[10:00:09] deploy failed after the symlink swap.",
    ]);

    expect(DeploymentPresenter::state($deployment)['label'])->toBe('Failed');
});

test('a deployment reached users only once its log shows the swap marker at the start of a line', function (string $log, bool $expected) {
    expect(DeploymentPresenter::reachedUsers(new Deployment(['log' => $log])))->toBe($expected);
})->with([
    'swap marker' => ["[10:00:00] clone: fetching\n[10:00:06] swap: current → releases/x", true],
    'no swap' => ["[10:00:00] clone: fetching\n[10:00:30] migrate: running", false],
    'swap mentioned mid line' => ['output mentioning [10:00:06] swap: in passing', false],
    'swap without its marker colon' => ['[10:00:06] swap current', false],
    'empty' => ['', false],
]);

test('a deployment row carries what the deployments table shows', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
    $server = Server::factory()->create(['name' => 'web-1']);
    $application = Application::factory()->for($server)->create(['name' => 'Shop', 'repository_branch' => 'main']);
    $deployment = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Succeeded,
        'release' => '20260913140000',
        'branch' => 'release/2.4',
        'started_at' => '2026-09-13 14:00:00',
        'finished_at' => '2026-09-13 14:01:35',
    ]);

    expect(DeploymentPresenter::row($deployment))->toBe([
        'id' => $deployment->id,
        'application' => 'Shop',
        'server' => 'web-1',
        'release' => '20260913-140000',
        'branch' => 'release/2.4',
        'when' => 'today 14:00',
        'full' => 'Sep 13, 2026 at 14:00 UTC',
        'duration' => '1m 35s',
        'state' => ['key' => 'deployed', 'label' => 'Deployed', 'tone' => 'success', 'live' => false],
        'href' => route('servers.applications.deployments.show', [$server, $application, $deployment]),
    ]);
});

test('a running deployment row has no duration yet and falls back to the application branch', function () {
    $application = Application::factory()->create(['repository_branch' => 'main']);
    $deployment = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'branch' => null,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $row = DeploymentPresenter::row($deployment);

    expect($row['duration'])->toBeNull()
        ->and($row['branch'])->toBe('main')
        ->and($row['state']['live'])->toBeTrue();
});

test('a queued deployment is dated by when it was created', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
    $deployment = Deployment::factory()->create(['started_at' => null, 'created_at' => '2026-09-10 09:30:00']);

    expect(DeploymentPresenter::row($deployment))->toMatchArray(['when' => 'Sep 10', 'full' => 'Sep 10, 2026 at 09:30 UTC', 'duration' => null]);
});

test('a deployment whose application is gone still renders without a link', function () {
    $deployment = new Deployment(['status' => DeploymentStatus::Succeeded, 'log' => '']);
    $deployment->setRelation('application', null);

    expect(DeploymentPresenter::row($deployment))->toMatchArray(['application' => '', 'server' => '', 'href' => null, 'branch' => null]);
});
