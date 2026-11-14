<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Enums\AppSize;
use App\Models\Application;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\NativePythonTools;
use App\Services\Runtime\NativeTools;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Stacks\PythonPresets\PythonPresets;

final readonly class Python implements Language
{
    public const VENV = '.venv';

    private const WORKERS = ['small' => 2, 'medium' => 4, 'large' => 8];

    private const DJANGO_PYTHON = ['2.2' => '3.9', '3.0' => '3.9', '3.1' => '3.9', '3.2' => '3.10', '4.0' => '3.10', '4.1' => '3.11', '4.2' => '3.12', '5.0' => '3.12', '5.1' => '3.13', '5.2' => '3.14', '6.0' => '3.14'];

    public function __construct(private AsdfManager $asdf, private JsCommands $js, private PythonDetection $detection, private PythonChecks $checks, private PythonPresets $presets) {}

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
        return $this->presets->find((string) $framework)?->initialEnvironment() ?? [];
    }

    public function processEnvironment(Application $application): array
    {
        return ['PYTHONUNBUFFERED' => '1', 'WEB_CONCURRENCY' => (string) self::workers($application)];
    }

    public static function workers(Application $application): int
    {
        $size = AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small;

        return self::WORKERS[$size->value] ?? self::WORKERS['small'];
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
        return $this->detection->detect($facts);
    }

    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        $domains = array_values(array_filter([$choice->domain, ...(array) $choice->additional_domains], fn(mixed $domain): bool => is_string($domain) && $domain !== ''));

        return $this->checks->checks($framework, $facts, $domains);
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        if ($runtime === null || $runtime->source !== ResolvedRuntime::FROM_DEFAULT) {
            return null;
        }

        $django = PythonDependencies::from($facts)->minor('django');
        $most = $django === null ? null : (self::DJANGO_PYTHON[$django] ?? null);
        $line = implode('.', array_slice(explode('.', $runtime->version), 0, 2));

        return $most !== null && version_compare($line, $most, '>')
            ? "Nothing names a Python version, so Coresmith would pick Python {$runtime->version}, and Django {$django} supports Python {$most} at most. Add a .python-version with the Python the app runs on, for example {$most}."
            : null;
    }

    public function cronExample(Application $application): string
    {
        return strtolower((string) $application->framework) === 'django' ? 'python manage.py clearsessions' : 'python cron/cleanup.py';
    }

    public function tooling(Application $application, RepositoryFacts $facts): array
    {
        $app = $this->detection->app($application->framework, $facts);

        return (new PythonTooling(PythonTooling::managerFor($facts), $app->server, $app->module, $app->collectstatic, $app->migrate, $this->detection->celery($facts)))->toArray();
    }

    public function pathDirectories(string $appDirectory): array
    {
        return [$appDirectory . '/' . self::VENV . '/bin'];
    }

    public function nativeTools(): ?NativeTools
    {
        return app(NativePythonTools::class);
    }

    public function frameworkLabel(string $framework): ?string
    {
        return $this->presets->find($framework)?->label();
    }

    public function explain(string $phase, string $output, Stack $stack): ?string
    {
        $python = $stack->runtimes['python'] ?? null;

        if ($phase !== DeploymentPhases::DEPENDENCIES || $python === null || preg_match('/Failed to (?:download and )?build `([A-Za-z0-9._-]+)/', $output, $matches) !== 1) {
            return null;
        }

        $package = $matches[1];

        return $python->source === ResolvedRuntime::FROM_DEFAULT
            ? "{$package} failed to build on Python {$python->version}, which Coresmith picked because the repository names no Python version. Add a .python-version with the Python the app runs on, for example 3.12, or update {$package}."
            : "{$package} failed to build on Python {$python->version}, the version {$python->source} names. Update {$package} to a release that supports it, or name an older Python in {$python->source}.";
    }
}
