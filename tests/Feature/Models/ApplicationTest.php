<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('an application belongs to a server and a team', function () {
    $app = Application::factory()->create();

    expect($app->server)->not->toBeNull()->and($app->team)->not->toBeNull();
});

test('an application slug is unique', function () {
    Application::factory()->create(['slug' => 'my-app']);

    Application::factory()->create(['slug' => 'my-app']);
})->throws(QueryException::class);

test('env variables are encrypted at rest', function () {
    $app = Application::factory()->create(['env_variables' => '{"APP_KEY":"secret"}']);

    expect(DB::table('applications')->where('id', $app->id)->value('env_variables'))->not->toContain('secret')
        ->and($app->fresh()->env_variables)->toBe('{"APP_KEY":"secret"}');
});

test('application enums cast correctly', function () {
    $app = Application::factory()->create(['type' => ApplicationType::Php, 'status' => ApplicationStatus::Active]);

    expect($app->fresh()->type)->toBe(ApplicationType::Php)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('an application has many deployments', function () {
    $app = Application::factory()->create();
    Deployment::factory()->count(3)->for($app)->create();

    expect($app->deployments)->toHaveCount(3);
});

test('a deployment records who triggered it', function () {
    $user = User::factory()->create();
    $deployment = Deployment::factory()->for($user, 'triggeredBy')->create();

    expect($deployment->triggeredBy->id)->toBe($user->id)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Pending);
});

test('a webhook secret is encrypted at rest', function () {
    $endpoint = WebhookEndpoint::factory()->create(['secret' => 'sh-top-secret']);

    expect(DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret'))->not->toContain('sh-top-secret')
        ->and($endpoint->fresh()->secret)->toBe('sh-top-secret');
});
