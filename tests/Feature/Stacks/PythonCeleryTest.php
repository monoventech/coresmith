<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Jobs\SyncApplicationProcesses;
use App\Models\Application;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use Illuminate\Support\Facades\Queue;

function celeryTooling(array $files, array $markers = []): array
{
    return ApplicationType::Python->language()->tooling(new Application(['type' => ApplicationType::Python, 'framework' => 'django']), new RepositoryFacts($files, markers: $markers, complete: true));
}

function celeryApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'type' => ApplicationType::Python,
        'framework' => 'django',
        'php_version' => null,
        'python_version' => '3.14.10',
        'app_size' => 'small',
        'stack' => ['language_tooling' => ['manager' => 'pip', 'server' => 'gunicorn', 'module' => 'shop.wsgi:application', 'celery' => 'shop']],
        ...$attributes,
    ]);
}

test('the Celery app is the package whose celery.py makes it, or the module that assigns it', function (array $files, ?string $celery) {
    $base = ['manage.py' => "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shop.settings')\n", 'shop/wsgi.py' => "application = get_wsgi_application()\n"];

    expect(celeryTooling([...$base, ...$files])['celery'])->toBe($celery);
})->with([
    'a Django project\'s celery.py' => [['requirements.txt' => "django\ncelery[redis]\n", 'shop/celery.py' => "from celery import Celery\n\napp = Celery('shop')\napp.autodiscover_tasks()\n"], 'shop'],
    'a tasks module' => [['requirements.txt' => "django\ncelery\n", 'tasks.py' => "from celery import Celery\n\ncelery = Celery('tasks', broker='redis://localhost')\n"], 'tasks:celery'],
    'celery with no app Coresmith can find' => [['requirements.txt' => "django\ncelery\n"], null],
    'no celery at all' => [['requirements.txt' => "django\n", 'shop/celery.py' => "app = Celery('shop')\n"], null],
]);

test('the probe reads the files a Celery app is made in', function () {
    expect(RepositoryProbe::GLOBS)->toContain('*/celery.py')
        ->and(RepositoryProbe::FILES)->toContain('tasks.py', 'worker.py', 'celery_app.py');
});

test('the Celery worker and beat run the app the stack names, with as many processes as the app size allows', function () {
    Queue::fake();
    $application = celeryApp();
    $toggles = app(SmartToggles::class);

    $toggles->apply($application, SmartToggles::CELERY, true);
    $toggles->apply($application->fresh(), SmartToggles::CELERY_BEAT, true);

    expect($application->fresh()->processes)->toBe([
        ['name' => 'celery', 'command' => 'celery -A shop worker --loglevel=INFO --concurrency=2'],
        ['name' => 'beat', 'command' => 'celery -A shop beat --loglevel=INFO --schedule=/tmp/celerybeat-schedule'],
    ]);

    $toggles->apply($application->fresh(), SmartToggles::CELERY, false);

    expect($application->fresh()->processes)->toBe([['name' => 'beat', 'command' => 'celery -A shop beat --loglevel=INFO --schedule=/tmp/celerybeat-schedule']]);
    Queue::assertPushed(SyncApplicationProcesses::class, fn(SyncApplicationProcesses $job): bool => (fn(): array => $this->removed)->call($job) === ['celery']);
});

test('the Celery toggles say what they run', function () {
    $described = collect(app(SmartToggles::class)->describe(celeryApp(['app_size' => 'large'])))->keyBy('key');

    expect($described[SmartToggles::CELERY])->toMatchArray(['label' => 'Celery worker', 'description' => 'celery -A shop worker', 'busy_on' => 'Starting the Celery worker…', 'busy_off' => 'Stopping the unit and removing it…', 'on' => false])
        ->and($described[SmartToggles::CELERY_BEAT])->toMatchArray(['label' => 'Celery beat', 'description' => 'celery -A shop beat', 'busy_on' => 'Starting Celery beat…', 'on' => false]);
});

test('a deploy moves running Celery processes onto the app and size the stack now names', function () {
    $application = celeryApp([
        'app_size' => 'medium',
        'processes' => [['name' => 'celery', 'command' => 'celery -A shop worker --loglevel=INFO --concurrency=2'], ['name' => 'beat', 'command' => 'celery -A shop beat --loglevel=INFO --schedule=/tmp/celerybeat-schedule']],
        'stack' => ['language_tooling' => ['manager' => 'pip', 'server' => 'gunicorn', 'module' => 'config.wsgi:application', 'celery' => 'config']],
    ]);

    app(SmartToggles::class)->syncDeployTimeProcesses($application);

    expect($application->fresh()->processes)->toBe([
        ['name' => 'celery', 'command' => 'celery -A config worker --loglevel=INFO --concurrency=4'],
        ['name' => 'beat', 'command' => 'celery -A config beat --loglevel=INFO --schedule=/tmp/celerybeat-schedule'],
    ]);
});
