<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use Illuminate\Support\Facades\Process;

function pythonFixture(string $fixture): RepositoryFacts
{
    $output = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => base_path('tests/Fixtures/repositories/' . $fixture)])->input(app(RepositoryProbe::class)->script())->run('bash -s')->output();

    return app(RepositoryProbe::class)->parse($output);
}

test('the probe reads a real Python repository, and detection names its framework and how it runs', function (string $fixture, string $framework, array $tooling) {
    $facts = pythonFixture($fixture);
    $proposal = ApplicationType::Python->language()->detect($facts);
    $application = new Application(['type' => ApplicationType::Python, 'framework' => $framework]);

    expect($proposal['type'])->toBe('python')
        ->and($proposal['framework'])->toBe($framework)
        ->and($proposal['unsettled'])->toBe([])
        ->and(ApplicationType::Python->language()->checks($application, $framework, $facts, 'x86_64'))->toBe([])
        ->and(ApplicationType::Python->language()->tooling($application, $facts))->toBe($tooling);
})->with([
    'Django with Celery' => ['django-celery', 'django', ['manager' => 'pip', 'server' => 'gunicorn', 'module' => 'shop.wsgi:application', 'collectstatic' => true, 'migrate' => 'django', 'celery' => 'shop']],
    'a Flask factory managed by uv' => ['flask-factory', 'flask', ['manager' => 'uv', 'server' => 'gunicorn', 'module' => 'notes:create_app()', 'collectstatic' => false, 'migrate' => null, 'celery' => null]],
    'FastAPI with Alembic' => ['fastapi-alembic', 'fastapi', ['manager' => 'pip', 'server' => 'uvicorn', 'module' => 'app.main:app', 'collectstatic' => false, 'migrate' => 'alembic', 'celery' => null]],
]);
