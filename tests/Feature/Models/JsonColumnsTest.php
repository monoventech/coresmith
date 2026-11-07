<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

function jsonColumnsReversed(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $value = array_map(jsonColumnsReversed(...), $value);

    return array_is_list($value) ? $value : array_reverse($value, true);
}

test('a json column given the same object in another key order has not changed', function (Closure $make, string $column) {
    $model = $make();
    $stored = $model::query()->findOrFail($model->getKey());

    $stored->setAttribute($column, jsonColumnsReversed($stored->getAttribute($column)));

    DB::enableQueryLog();
    $stored->save();

    expect($stored->wasChanged($column))->toBeFalse()
        ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn(string $query): bool => str_starts_with($query, 'update'))->all())->toBe([]);
})->with([
    'the toggles of an app' => [fn(): Model => Application::factory()->create(['toggles' => ['maintenance_mode' => true, 'inertia_ssr' => false]]), 'toggles'],
    'the cron jobs of an app' => [fn(): Model => Application::factory()->create(['cron_jobs' => [['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup']]]), 'cron_jobs'],
    'the stack of an app' => [fn(): Model => Application::factory()->create(['stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => '8.5.10', 'source' => 'default', 'detail' => null]], 'framework' => ['name' => 'laravel', 'major' => 12]]]), 'stack'],
    'the meta of a server' => [fn(): Model => Server::factory()->create(['meta' => ['memory_mb' => 8000, 'cpu_cores' => 4, 'installed_databases' => ['postgresql', 'mysql']]]), 'meta'],
    'the task of a server' => [fn(): Model => Server::factory()->create(['provider_task' => ['type' => 'resize', 'state' => 'running', 'step' => 'shutdown', 'data' => ['to' => 'cx32', 'from' => 'cx22']]]), 'provider_task'],
    'the stack of a deployment' => [fn(): Model => Deployment::factory()->create(['stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => '8.5.10', 'source' => 'default', 'detail' => null]], 'tooling' => null]]), 'stack'],
    'the preferences of a user' => [fn(): Model => User::factory()->create(['notification_preferences' => ['deploy_failed' => true, 'weekly_digest' => true, 'provisioning' => false]]), 'notification_preferences'],
    'the permissions of a github app' => [fn(): Model => GitHubApp::factory()->create(['permissions' => ['metadata' => 'read', 'contents' => 'read', 'statuses' => 'write']]), 'permissions'],
]);

test('a json column still changes when a list is reordered, a key is added or dropped, or a value changes', function (Closure $change) {
    $application = Application::factory()->create([
        'toggles' => ['maintenance_mode' => false, 'reverb' => true],
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'], ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']],
    ]);
    $stored = Application::query()->findOrFail($application->getKey());

    $change($stored);
    $stored->save();

    expect($stored->wasChanged(['toggles', 'cron_jobs']))->toBeTrue();
})->with([
    'a list in another order' => [fn(Application $application) => $application->cron_jobs = array_reverse($application->cron_jobs)],
    'a key added' => [fn(Application $application) => $application->toggles = [...$application->toggles, 'inertia_ssr' => false]],
    'a key dropped' => [fn(Application $application) => $application->toggles = ['reverb' => true]],
    'a value changed' => [fn(Application $application) => $application->toggles = ['reverb' => true, 'maintenance_mode' => true]],
    'a value of another type' => [fn(Application $application) => $application->toggles = ['reverb' => 1, 'maintenance_mode' => false]],
    'a nested value changed' => [fn(Application $application) => $application->cron_jobs = [$application->cron_jobs[0], ['command' => 'php artisan backup:run', 'schedule' => '0 4 * * *']]],
    'emptied' => [fn(Application $application) => $application->toggles = null],
]);

test('a json column whose stored text is unchanged is not decoded to tell', function () {
    $application = new class extends Application
    {
        public int $decoded = 0;

        public function fromJson($value, $asObject = false)
        {
            $this->decoded++;

            return parent::fromJson($value, $asObject);
        }
    };
    $application->setRawAttributes(['toggles' => '{"reverb":true,"maintenance_mode":false}'], true);

    expect($application->isDirty('toggles'))->toBeFalse()
        ->and($application->decoded)->toBe(0);
});

test('every model with a json column ignores key order in its dirty checks', function () {
    $models = collect(glob(app_path('Models/*.php')) ?: [])
        ->map(fn(string $path): string => 'App\\Models\\' . basename($path, '.php'))
        ->filter(fn(string $class): bool => is_subclass_of($class, Model::class));
    $casts = $models->mapWithKeys(fn(string $class): array => [$class => array_values((new $class)->getCasts())]);
    $json = $casts->filter(fn(array $types): bool => array_intersect($types, ['array', 'json']) !== [])->keys();
    $uncovered = $casts->map(fn(array $types): array => array_values(array_filter($types, fn(string $type): bool => in_array(strtolower($type), ['json:unicode', 'object', 'collection'], true) || str_starts_with(strtolower($type), 'encrypted:') || str_contains($type, 'AsArrayObject') || str_contains($type, 'AsCollection'))))->filter();

    expect($json->reject(fn(string $class): bool => in_array(IgnoresJsonKeyOrder::class, class_uses_recursive($class), true))->values()->all())->toBe([])
        ->and($json->all())->toContain(Application::class, Server::class, Deployment::class, User::class, GitHubApp::class, SourceProvider::class, ManagedDatabase::class)
        ->and($uncovered->all())->toBe([]);
});
