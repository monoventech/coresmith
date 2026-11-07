<?php

declare(strict_types=1);

use App\Jobs\SyncStripeCustomerEmail;
use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @return list<class-string>
 */
function queuedModelsClasses(string $directory, string $namespace): array
{
    return collect(glob(app_path($directory) . '/*.php') ?: [])
        ->map(fn(string $file): string => $namespace . basename($file, '.php'))
        ->filter(fn(string $class): bool => class_exists($class) && is_subclass_of($class, ShouldQueue::class))
        ->values()
        ->all();
}

function queuedModelsCarriesModel(string $class): bool
{
    foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        foreach ($types as $named) {
            if ($named instanceof ReflectionNamedType && is_subclass_of($named->getName(), Model::class)) {
                return true;
            }
        }
    }

    return false;
}

test('queued work that carries a model drops itself when that model is gone', function () {
    $carriers = collect([...queuedModelsClasses('Jobs', 'App\\Jobs\\'), ...queuedModelsClasses('Notifications', 'App\\Notifications\\')])
        ->filter(fn(string $class): bool => queuedModelsCarriesModel($class))
        ->values();

    $kept = $carriers
        ->reject(fn(string $class): bool => (new ReflectionClass($class))->getDefaultProperties()['deleteWhenMissingModels'] ?? false)
        ->values()
        ->all();

    expect($carriers->count())->toBeGreaterThan(30)
        ->and($kept)->toBe([]);
});

test('a job queued for a team that is then deleted is dropped, not failed', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => config('database.default')]);
    $failed = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failed): void {
        $failed[] = $event->exception::class;
    });
    $team = Team::factory()->create(['stripe_id' => 'cus_gone']);
    SyncStripeCustomerEmail::dispatch($team);
    $team->forceDelete();

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--stop-when-empty' => true]);

    expect($failed)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0);
});
