<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Enums\AppSize;
use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\NativePythonTools;
use App\Services\Runtime\NativeTools;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;

final readonly class Python implements Language
{
    public const VENV = '.venv';

    private const WORKERS = ['small' => 2, 'medium' => 4, 'large' => 8];

    public function __construct(private AsdfManager $asdf, private JsCommands $js) {}

    public function install(Application $application, Stack $stack): array
    {
        $tooling = PythonTooling::fromArray($stack->languageTooling);
        $version = $stack->versions()['python'] ?? null;

        if ($tooling === null || $version === null) {
            return [];
        }

        $uv = $this->asdf->uvPath();
        $uvx = dirname($uv) . '/uvx';
        $python = $this->asdf->binaryPath('python', $version);
        $venv = self::VENV . '/bin/python';

        $dependencies = match ($tooling->manager) {
            'uv' => "{$uv} sync --frozen --no-dev --python {$python}",
            'poetry' => "POETRY_VIRTUALENVS_IN_PROJECT=true {$uvx} --python {$python} poetry install --only main --no-root --no-interaction",
            'pipenv' => "{$uvx} --python {$python} pipenv requirements > /tmp/requirements.txt && {$uv} pip install --python {$venv} -r /tmp/requirements.txt",
            'pip' => "{$uv} pip install --python {$venv} -r requirements.txt",
            'pyproject' => "{$uv} pip install --python {$venv} -r pyproject.toml",
            default => null,
        };

        return [
            "{$uv} venv --clear --python {$python} " . self::VENV,
            ...($dependencies === null ? [] : [$dependencies]),
            $tooling->server === 'uvicorn'
                ? 'test -x ' . self::VENV . "/bin/uvicorn || {$uv} pip install --python {$venv} 'uvicorn[standard]'"
                : 'test -x ' . self::VENV . "/bin/gunicorn || {$uv} pip install --python {$venv} gunicorn",
            ...$this->js->install($stack),
        ];
    }

    public function build(Application $application, Stack $stack): array
    {
        $collect = PythonTooling::fromArray($stack->languageTooling)?->collectstatic === true;

        return [...$this->js->build($application, $stack), ...($collect ? ['python manage.py collectstatic --noinput'] : [])];
    }

    public function migrations(Application $application, Stack $stack): array
    {
        return match (PythonTooling::fromArray($stack->languageTooling)?->migrate) {
            'django' => ['python manage.py migrate --noinput'],
            'alembic' => ['alembic upgrade head'],
            default => [],
        };
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        return [self::VENV . '/bin/python' => 'test -x ' . escapeshellarg($paths->appDirectory($release) . '/' . self::VENV . '/bin/python')];
    }

    public function webCommand(Application $application): ?string
    {
        $data = ((array) $application->stack)['language_tooling'] ?? null;
        $tooling = PythonTooling::fromArray(is_array($data) ? $data : null);

        if ($tooling?->module === null) {
            return null;
        }

        $paths = new ReleasePaths($application);
        $bin = $paths->appDirectory($paths->current()) . '/' . self::VENV . '/bin/';
        $port = (int) $application->base_port;

        return $tooling->server === 'uvicorn'
            ? $bin . 'uvicorn --host 127.0.0.1 --port ' . $port . (str_ends_with($tooling->module, '()') ? ' --factory ' . substr($tooling->module, 0, -2) : ' ' . $tooling->module)
            : $bin . 'gunicorn --bind 127.0.0.1:' . $port . ' --access-logfile - ' . $tooling->module;
    }

    public function usesFpm(): bool
    {
        return false;
    }

    public function route(Application $application): Route
    {
        return Route::ReverseProxy;
    }

    public function storageSeed(): StorageSeed
    {
        return StorageSeed::None;
    }

    public function initialEnvironment(?string $framework): array
    {
        return [];
    }

    public function processEnvironment(Application $application): array
    {
        $size = AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small;

        return ['PYTHONUNBUFFERED' => '1', 'WEB_CONCURRENCY' => (string) (self::WORKERS[$size->value] ?? self::WORKERS['small'])];
    }

    public function listenEnvironment(Application $application): array
    {
        return ['PORT' => (string) $application->base_port, 'HOST' => '127.0.0.1'];
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        return null;
    }

    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        return [];
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        return null;
    }

    public function cronExample(Application $application): string
    {
        return strtolower((string) $application->framework) === 'django' ? 'python manage.py clearsessions' : 'python cron/cleanup.py';
    }

    public function tooling(Application $application, RepositoryFacts $facts): array
    {
        return (new PythonTooling($this->manager($facts), 'gunicorn', null))->toArray();
    }

    public function pathDirectories(string $appDirectory): array
    {
        return [$appDirectory . '/' . self::VENV . '/bin'];
    }

    public function nativeTools(): ?NativeTools
    {
        return app(NativePythonTools::class);
    }

    private function manager(RepositoryFacts $facts): string
    {
        return match (true) {
            $facts->exists('uv.lock') => 'uv',
            $facts->exists('poetry.lock') => 'poetry',
            $facts->exists('Pipfile.lock') => 'pipenv',
            $facts->has('requirements.txt') => 'pip',
            $facts->toml('pyproject.toml', 'tool.poetry.dependencies') !== [] || $facts->toml('pyproject.toml', 'tool.poetry') !== [] => 'poetry',
            $facts->has('Pipfile') => 'pipenv',
            $facts->toml('pyproject.toml', 'project') !== [] => 'pyproject',
            default => 'none',
        };
    }
}
