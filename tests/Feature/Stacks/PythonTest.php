<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Moves\MoveColumns;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Stacks\Python;
use App\Stacks\Route;
use App\Stacks\StorageSeed;

function pythonApp(array $attributes = []): Application
{
    return new Application(['type' => ApplicationType::Python, 'framework' => 'django', 'python_version' => '3.14.10', 'base_port' => 4300, ...$attributes]);
}

test('a Python app runs a web process of its own behind Caddy, with no PHP-FPM, no web root and no shared storage', function () {
    $language = ApplicationType::Python->language();

    expect($language)->toBeInstanceOf(Python::class)
        ->and(ApplicationType::Python->runtime())->toBe('python')
        ->and($language->usesFpm())->toBeFalse()
        ->and($language->route(pythonApp()))->toBe(Route::ReverseProxy)
        ->and($language->route(pythonApp())->servesFiles())->toBeFalse()
        ->and($language->storageSeed())->toBe(StorageSeed::None);
});

test('a Python app listens on its own port and writes its logs as they happen', function () {
    expect(ApplicationType::Python->language()->listenEnvironment(pythonApp()))->toBe(['PORT' => '4300', 'HOST' => '127.0.0.1'])
        ->and(ApplicationType::Python->language()->processEnvironment(pythonApp()))->toBe(['PYTHONUNBUFFERED' => '1']);
});

test('the cron example is a Django command for Django apps and a script for the others', function () {
    expect(ApplicationType::Python->language()->cronExample(pythonApp()))->toBe('python manage.py clearsessions')
        ->and(ApplicationType::Python->language()->cronExample(pythonApp(['framework' => 'flask'])))->toBe('python cron/cleanup.py');
});

test('a Python app keeps the Python version it was deployed with', function () {
    $stack = new Stack(['python' => new ResolvedRuntime('python', '3.13.18', '.python-version', '3.13', false)]);

    expect(pythonApp()->runtimeVersions())->toBe(['python' => '3.14.10'])
        ->and($stack->applicationAttributes()['python_version'])->toBe('3.13.18');
});

test('the app page names Python and its line', function () {
    expect(ApplicationPresenter::runtime(pythonApp()))->toBe('Python 3.14')
        ->and(ApplicationPresenter::kind(pythonApp(['framework' => null])))->toBe('Python');
});

test('a Python line past its end of life says where to name a newer one', function (?string $source, string $fix) {
    $this->travelTo('2027-11-01');
    $application = pythonApp(['python_version' => '3.11.18', 'stack' => $source === null ? [] : ['runtimes' => ['python' => ['source' => $source]]]]);

    expect(ApplicationPresenter::runtimeEol($application))->toBe(['runtime' => 'Python 3.11', 'ended' => '31 October 2027', 'fix' => $fix]);
})->with([
    'nothing named it' => [null, 'Add a .python-version containing 3.14 to the repository, then deploy.'],
    '.python-version' => ['.python-version', 'Set .python-version to 3.14, then deploy.'],
    'requires-python' => ['pyproject.toml', 'Set requires-python in pyproject.toml to >=3.14, then deploy.'],
    'runtime.txt' => ['runtime.txt', 'Set runtime.txt to python-3.14, then deploy.'],
    '.tool-versions' => ['.tool-versions', 'Set python in .tool-versions to 3.14, then deploy.'],
]);

test('moving an app to another team carries its Python version', function () {
    expect(MoveColumns::APPLICATIONS)->toContain('python_version');
});
