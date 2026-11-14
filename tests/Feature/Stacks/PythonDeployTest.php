<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\AppSize;
use App\Models\Application;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\ReleasePaths;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Services\Runtime\StackResolver;
use App\Stacks\PythonTooling;

const PYTHON_UV = '/opt/asdf-data/uv/0.13.6/uv';
const PYTHON_UVX = '/opt/asdf-data/uv/0.13.6/uvx';
const PYTHON_BIN = '/opt/asdf-data/installs/python/3.14.10/bin/python';

function pythonDeployStack(PythonTooling $tooling): Stack
{
    return new Stack(['python' => new ResolvedRuntime('python', '3.14.10', '.python-version', '3.14', true)], languageTooling: $tooling->toArray());
}

function pythonDeployApp(array $attributes = []): Application
{
    return new Application(['type' => ApplicationType::Python, 'framework' => 'django', 'slug' => 'shop', 'system_user' => 'coresmith-app-shop', 'python_version' => '3.14.10', 'base_port' => 4300, 'app_size' => 'small', ...$attributes]);
}

test('dependencies install into a fresh virtualenv with the tool the repository locks them with', function (string $manager, string $install) {
    $commands = ApplicationType::Python->language()->install(pythonDeployApp(), pythonDeployStack(new PythonTooling($manager, 'gunicorn', 'shop.wsgi:application')));

    expect($commands)->toBe([
        PYTHON_UV . ' venv --clear --python ' . PYTHON_BIN . ' .venv',
        $install,
        'test -x .venv/bin/gunicorn || ' . PYTHON_UV . ' pip install --python .venv/bin/python gunicorn',
    ]);
})->with([
    'uv.lock' => ['uv', PYTHON_UV . ' sync --frozen --no-dev --python ' . PYTHON_BIN],
    'poetry.lock' => ['poetry', 'POETRY_VIRTUALENVS_IN_PROJECT=true ' . PYTHON_UVX . ' --python ' . PYTHON_BIN . ' poetry install --only main --no-root --no-interaction'],
    'Pipfile.lock' => ['pipenv', PYTHON_UVX . ' --python ' . PYTHON_BIN . ' pipenv requirements > /tmp/requirements.txt && ' . PYTHON_UV . ' pip install --python .venv/bin/python -r /tmp/requirements.txt'],
    'requirements.txt' => ['pip', PYTHON_UV . ' pip install --python .venv/bin/python -r requirements.txt'],
    'pyproject.toml alone' => ['pyproject', PYTHON_UV . ' pip install --python .venv/bin/python -r pyproject.toml'],
]);

test('an ASGI app gets uvicorn with its standard extras when the project does not list it', function () {
    $commands = ApplicationType::Python->language()->install(pythonDeployApp(['framework' => 'fastapi']), pythonDeployStack(new PythonTooling('pip', 'uvicorn', 'app.main:app')));

    expect($commands[2])->toBe('test -x .venv/bin/uvicorn || ' . PYTHON_UV . " pip install --python .venv/bin/python 'uvicorn[standard]'");
});

test('Django collects its static files only when settings name a STATIC_ROOT, after any JavaScript build', function () {
    $python = ApplicationType::Python->language();

    expect($python->build(pythonDeployApp(), pythonDeployStack(new PythonTooling('pip', 'gunicorn', 'shop.wsgi:application', collectstatic: true))))->toBe(['python manage.py collectstatic --noinput'])
        ->and($python->build(pythonDeployApp(), pythonDeployStack(new PythonTooling('pip', 'gunicorn', 'shop.wsgi:application'))))->toBe([]);
});

test('migrations run with the tool the app migrates with', function (?string $migrate, array $commands) {
    expect(ApplicationType::Python->language()->migrations(pythonDeployApp(), pythonDeployStack(new PythonTooling('pip', 'gunicorn', 'shop.wsgi:application', migrate: $migrate))))->toBe($commands);
})->with([
    'Django' => ['django', ['python manage.py migrate --noinput']],
    'Alembic' => ['alembic', ['alembic upgrade head']],
    'nothing to migrate' => [null, []],
]);

test('the release goes live only with its virtualenv in place', function () {
    $application = pythonDeployApp(['directory' => '/api']);
    $paths = new ReleasePaths($application);
    $release = $paths->releases() . '/20261114-070000';

    expect(ApplicationType::Python->language()->releaseChecks($application, $paths, $release))->toBe([
        '.venv/bin/python' => 'test -x ' . escapeshellarg($release . '/api/.venv/bin/python'),
    ]);
});

test('an app that lists no dependencies still gets its virtualenv and server', function () {
    expect(ApplicationType::Python->language()->install(pythonDeployApp(), pythonDeployStack(new PythonTooling('none', 'gunicorn', 'app:app'))))->toBe([
        PYTHON_UV . ' venv --clear --python ' . PYTHON_BIN . ' .venv',
        'test -x .venv/bin/gunicorn || ' . PYTHON_UV . ' pip install --python .venv/bin/python gunicorn',
    ]);
});

test('WSGI apps run under gunicorn and ASGI apps under uvicorn, from the live release\'s virtualenv', function () {
    $python = ApplicationType::Python->language();
    $live = '/var/coresmith/apps/shop/current';

    expect($python->webCommand(pythonDeployApp(['stack' => ['language_tooling' => (new PythonTooling('pip', 'gunicorn', 'shop.wsgi:application'))->toArray()]])))
        ->toBe("{$live}/.venv/bin/gunicorn --bind 127.0.0.1:4300 --access-logfile - shop.wsgi:application")
        ->and($python->webCommand(pythonDeployApp(['framework' => 'fastapi', 'stack' => ['language_tooling' => (new PythonTooling('uv', 'uvicorn', 'app.main:app'))->toArray()]])))
        ->toBe("{$live}/.venv/bin/uvicorn --host 127.0.0.1 --port 4300 app.main:app")
        ->and($python->webCommand(pythonDeployApp(['directory' => '/api', 'stack' => ['language_tooling' => (new PythonTooling('pip', 'gunicorn', 'api.wsgi:application'))->toArray()]])))
        ->toBe("{$live}/api/.venv/bin/gunicorn --bind 127.0.0.1:4300 --access-logfile - api.wsgi:application")
        ->and($python->webCommand(pythonDeployApp(['stack' => []])))->toBeNull();
});

test('the app size sets how many workers serve it, and the app can change that in its environment', function (string $size, string $workers) {
    expect(ApplicationType::Python->language()->processEnvironment(pythonDeployApp(['app_size' => $size])))->toBe(['PYTHONUNBUFFERED' => '1', 'WEB_CONCURRENCY' => $workers]);
})->with([
    'small' => [AppSize::Small->value, '2'],
    'medium' => [AppSize::Medium->value, '4'],
    'large' => [AppSize::Large->value, '8'],
    'custom' => [AppSize::Custom->value, '2'],
]);

test('the web process, cron jobs and deploy steps find the virtualenv first on PATH', function () {
    $application = Application::factory()->create(['type' => ApplicationType::Python, 'framework' => 'django', 'slug' => 'shop', 'system_user' => 'coresmith-app-shop', 'python_version' => '3.14.10', 'php_version' => null, 'base_port' => 4300]);
    $unit = app(SystemdUnitBuilder::class)->build($application, 'web', '/bin/true');
    $step = app(AppShell::class)->run($application, 'python manage.py migrate --noinput', '/var/coresmith/apps/shop/releases/20261114-070000', ['python' => '3.14.10']);

    expect($unit)->toContain('Environment="PATH=/var/coresmith/apps/shop/current/.venv/bin:/opt/asdf-data/installs/python/3.14.10/bin:/usr/local/bin:/usr/bin:/bin"')
        ->and($step)->toContain(escapeshellarg('PATH=/var/coresmith/apps/shop/releases/20261114-070000/.venv/bin:/opt/asdf-data/installs/python/3.14.10/bin:' . AppShell::SYSTEM_PATH));
});

test('a stack keeps the language\'s tooling through storage', function () {
    $tooling = new PythonTooling('poetry', 'uvicorn', 'app.main:app', collectstatic: false, migrate: 'alembic');
    $stored = pythonDeployStack($tooling)->toArray();

    expect($stored['language_tooling'])->toBe(['manager' => 'poetry', 'server' => 'uvicorn', 'module' => 'app.main:app', 'collectstatic' => false, 'migrate' => 'alembic', 'celery' => null])
        ->and(Stack::fromArray($stored)->languageTooling)->toBe($stored['language_tooling'])
        ->and(Stack::fromArray($stored)->withRuntimes([])->languageTooling)->toBe($stored['language_tooling'])
        ->and(PythonTooling::fromArray(['manager' => 'npm', 'server' => 'gunicorn']))->toBeNull()
        ->and(PythonTooling::fromArray(['manager' => 'pip', 'server' => 'gunicorn', 'module' => 'shop.wsgi:application; rm -rf /']))->toBeNull()
        ->and(PythonTooling::fromArray(null))->toBeNull();
});

test('the tool comes from the lockfile, and a lockfile wins over requirements.txt', function (array $files, array $markers, string $manager) {
    $application = pythonDeployApp();
    $stack = app(StackResolver::class)->resolve($application, new RepositoryFacts($files, markers: $markers, complete: true), fn(string $runtime): array => []);

    expect(PythonTooling::fromArray($stack->languageTooling)?->manager)->toBe($manager);
})->with([
    'uv.lock' => [['pyproject.toml' => "[project]\nname = \"shop\"\n", 'requirements.txt' => "django\n"], ['uv.lock'], 'uv'],
    'poetry.lock over requirements.txt' => [['pyproject.toml' => "[tool.poetry]\nname = \"shop\"\n", 'requirements.txt' => "django\n"], ['poetry.lock'], 'poetry'],
    'Pipfile.lock' => [['Pipfile' => "[packages]\ndjango = \"*\"\n"], ['Pipfile.lock'], 'pipenv'],
    'requirements.txt' => [['requirements.txt' => "django\n", 'pyproject.toml' => "[tool.black]\n"], [], 'pip'],
    'pyproject.toml alone' => [['pyproject.toml' => "[project]\nname = \"shop\"\ndependencies = [\"django\"]\n"], [], 'pyproject'],
    'Poetry without its lockfile' => [['pyproject.toml' => "[tool.poetry.dependencies]\npython = \"^3.12\"\ndjango = \"^5.1\"\n"], [], 'poetry'],
    'Pipfile without its lockfile' => [['Pipfile' => "[packages]\ndjango = \"*\"\n"], [], 'pipenv'],
    'nothing listed' => [['pyproject.toml' => "[tool.black]\nline-length = 100\n"], [], 'none'],
]);

test('the probe looks for the lockfiles that name the Python tool', function () {
    expect(RepositoryProbe::MARKERS)->toContain('uv.lock', 'poetry.lock', 'Pipfile.lock')
        ->and(RepositoryProbe::FILES)->toContain('requirements.txt', 'Pipfile');
});
