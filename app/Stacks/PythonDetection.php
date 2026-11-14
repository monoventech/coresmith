<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;
use App\Stacks\PythonPresets\PythonPresets;

final readonly class PythonDetection
{
    private const SERVERS = ['gunicorn', 'uvicorn'];

    private const FLAGS = ['--reload', '--preload', '--daemon', '-D', '--check-config', '--print-config', '--spew', '--capture-output', '--enable-stdio-inheritance', '--no-sendfile', '--reuse-port', '--strip-header-spaces', '-R', '--proxy-headers', '--no-proxy-headers', '--server-header', '--no-server-header', '--date-header', '--no-date-header', '--access-log', '--no-access-log', '--use-colors', '--no-use-colors', '--factory'];

    private const OTHER_LANGUAGES = ['package.json', 'deno.json', 'deno.jsonc'];

    private const OTHER_MARKERS = ['index.html', 'public/index.html'];

    public function __construct(private PythonPresets $presets) {}

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>, fields?: array<string, ?string>, options?: array<string, list<string>>}|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        $dependencies = PythonDependencies::from($facts);
        $web = $this->procfileWeb($facts);

        if (!$dependencies->any() && !$facts->has('manage.py') && !$this->procfileRunsPython($web)) {
            return null;
        }

        $sources = ['type' => $this->typeSource($facts)];
        $preset = $this->presets->claiming($dependencies);

        if ($preset !== null) {
            $app = $preset->app($facts, $dependencies);

            return Proposal::make('python', $preset->key(), null, null, [...$sources, 'framework' => $preset->evidence($facts, $dependencies)], $app->unsettled() === null ? [] : ['framework' => $app->unsettled()]);
        }

        $app = $this->generic($facts);

        if ($app->module !== null) {
            $evidence = $web === null ? (string) preg_replace('/^(\w+):(\w+)$/', '$2 in $1.py', $app->module) : 'web: ' . $this->program($web) . ' in Procfile';

            return Proposal::make('python', '', null, null, [...$sources, 'framework' => $evidence], []);
        }

        if ($web !== null) {
            return Proposal::make('python', '', null, null, $sources, ['framework' => (string) $app->unsettled()]);
        }

        foreach (self::OTHER_LANGUAGES as $file) {
            if ($facts->has($file)) {
                return null;
            }
        }

        foreach (self::OTHER_MARKERS as $marker) {
            if ($facts->exists($marker)) {
                return null;
            }
        }

        return Proposal::make('python', null, null, null, $sources, ['framework' => (string) $app->unsettled()]);
    }

    public function app(?string $framework, RepositoryFacts $facts): PythonApp
    {
        $preset = $this->presets->find((string) $framework);

        return $preset === null ? $this->generic($facts) : $preset->app($facts, PythonDependencies::from($facts));
    }

    public function celery(RepositoryFacts $facts): ?string
    {
        if (!PythonDependencies::from($facts)->has('celery')) {
            return null;
        }

        foreach ($facts->matching('*/celery.py') as $path => $source) {
            if (PythonSource::assigned($source, ['Celery']) !== null && PythonSource::module(dirname($path) . '.py') !== null) {
                return dirname($path);
            }
        }

        foreach (['celery_app.py', 'tasks.py', 'worker.py'] as $file) {
            $variable = PythonSource::assigned((string) $facts->file($file), ['Celery']);

            if ($variable !== null) {
                return basename($file, '.py') . ':' . $variable;
            }
        }

        return null;
    }

    private function generic(RepositoryFacts $facts): PythonApp
    {
        $web = $this->procfileWeb($facts);

        if ($web !== null) {
            return $this->fromProcfile($web);
        }

        foreach (['wsgi.py' => 'gunicorn', 'asgi.py' => 'uvicorn'] as $file => $server) {
            if (preg_match('/^(application|app)\s*(?::[^=\n]+)?=/m', (string) $facts->file($file), $matches) === 1) {
                return new PythonApp($server, basename($file, '.py') . ':' . $matches[1]);
            }
        }

        return PythonApp::missing('gunicorn', (PythonDependencies::list($facts) ?? 'The repository') . " names no web framework Coresmith knows, and there is no Procfile, so Coresmith can't tell how to start the app.", 'Add a Procfile with a web line, for example web: gunicorn app:app.');
    }

    private function procfileWeb(RepositoryFacts $facts): ?string
    {
        foreach (preg_split('/\r\n|\n|\r/', (string) $facts->file('Procfile')) ?: [] as $line) {
            if (preg_match('/^web:\s*(\S.*)$/', trim($line), $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function procfileRunsPython(?string $web): bool
    {
        return $web !== null && preg_match('/(?:^|\s|\/)(?:gunicorn|uvicorn|python3?|daphne|hypercorn|waitress-serve)(?:\s|$)/', $web) === 1;
    }

    private function program(string $web): string
    {
        foreach (preg_split('/\s+/', $web) ?: [] as $token) {
            if (in_array(basename($token), self::SERVERS, true)) {
                return basename($token);
            }
        }

        return explode(' ', $web)[0];
    }

    private function fromProcfile(string $web): PythonApp
    {
        $tokens = preg_split('/\s+/', $web) ?: [];
        $at = null;

        foreach ($tokens as $index => $token) {
            if (in_array(basename($token), self::SERVERS, true)) {
                $at = $index;

                break;
            }
        }

        if ($at === null) {
            return PythonApp::missing('gunicorn', "Procfile starts the app with {$web}, and Coresmith runs Python apps with gunicorn or uvicorn.", 'Change the web line to name the app, for example web: gunicorn app:app.');
        }

        $server = basename($tokens[$at]);
        $module = null;
        $factory = false;
        $rest = array_values(array_slice($tokens, $at + 1));

        for ($index = 0; $index < count($rest); $index++) {
            $token = $rest[$index];

            if (str_starts_with($token, '-')) {
                [$option, $value] = str_contains($token, '=') ? explode('=', $token, 2) : [$token, in_array($token, self::FLAGS, true) ? '' : ($rest[++$index] ?? '')];
                $server = in_array($option, ['-k', '--worker-class'], true) && str_contains($value, 'uvicorn') ? 'uvicorn' : $server;
                $factory = $factory || $option === '--factory';

                continue;
            }

            if ($module === null && preg_match(PythonTooling::MODULE, $token) === 1) {
                $module = $token;
            }
        }

        if ($module === null) {
            return PythonApp::missing($server, "Procfile's web line runs {$server} without naming the app.", "Name it after {$server}, for example web: {$server} app:app.");
        }

        return new PythonApp($server, $factory && !str_ends_with($module, '()') ? $module . '()' : $module);
    }

    private function typeSource(RepositoryFacts $facts): string
    {
        return match (PythonTooling::managerFor($facts)) {
            'uv' => 'From pyproject.toml and uv.lock',
            'poetry' => $facts->exists('poetry.lock') ? 'From pyproject.toml and poetry.lock' : 'From pyproject.toml',
            'pipenv' => $facts->exists('Pipfile.lock') ? 'From Pipfile and Pipfile.lock' : 'From Pipfile',
            'pip' => 'From requirements.txt',
            'pyproject' => 'From pyproject.toml',
            default => $facts->has('manage.py') ? 'From manage.py' : 'From Procfile',
        };
    }
}
