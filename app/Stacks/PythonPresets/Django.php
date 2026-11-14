<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\PythonPresets;

use App\Services\Runtime\RepositoryFacts;
use App\Stacks\PythonApp;
use App\Stacks\PythonDependencies;

final class Django extends PythonPreset
{
    private const SETTINGS = ['*/settings.py', '*/settings/*.py'];

    public function key(): string
    {
        return 'django';
    }

    public function label(): string
    {
        return 'Django';
    }

    public function package(): string
    {
        return 'django';
    }

    public function evidence(RepositoryFacts $facts, PythonDependencies $dependencies): string
    {
        return parent::evidence($facts, $dependencies) . ($facts->has('manage.py') ? ', with manage.py' : '');
    }

    public function app(RepositoryFacts $facts, PythonDependencies $dependencies): PythonApp
    {
        if (!$facts->has('manage.py')) {
            return PythonApp::missing('gunicorn', "django is in {$dependencies->file('django')}, but there is no manage.py, so this looks like a package for Django, not a Django project.", 'Pick the folder that holds manage.py, or the framework the app uses.');
        }

        $module = $this->settingsModule($facts);
        $settings = $this->settings($facts, $module);
        $collect = $this->setting($settings, 'STATIC_ROOT') !== null;
        $asgi = $this->dotted($this->setting($settings, 'ASGI_APPLICATION'));
        $wsgi = $this->dotted($this->setting($settings, 'WSGI_APPLICATION'));

        if ($asgi !== null || $wsgi !== null) {
            return new PythonApp($asgi === null ? 'gunicorn' : 'uvicorn', $asgi ?? $wsgi, collectstatic: $collect, migrate: 'django');
        }

        $project = $module === null ? $this->projectOf(array_key_first($settings)) : explode('.', $module)[0];

        if ($project !== null && $facts->has("{$project}/wsgi.py")) {
            return new PythonApp('gunicorn', "{$project}.wsgi:application", collectstatic: $collect, migrate: 'django');
        }

        if ($project === null) {
            return PythonApp::missing('gunicorn', "Coresmith found no settings that set WSGI_APPLICATION and no wsgi.py beside them, so it can't tell what gunicorn should load.", 'Commit the wsgi.py that django-admin startproject writes, next to settings.py.');
        }

        $named = $module === null ? array_key_first($settings) . " doesn't" : "manage.py names {$module}, but those settings don't";

        return PythonApp::missing('gunicorn', "{$named} set WSGI_APPLICATION and there is no {$project}/wsgi.py, so Coresmith can't tell what gunicorn should load.", "Commit the {$project}/wsgi.py that django-admin startproject writes.");
    }

    public function initialEnvironment(): array
    {
        return ['SECRET_KEY' => bin2hex(random_bytes(32))];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function emptyAllowedHosts(RepositoryFacts $facts): ?array
    {
        $settings = $this->settings($facts, $this->settingsModule($facts));
        $empty = null;

        foreach ($settings as $path => $source) {
            if (preg_match('/^ALLOWED_HOSTS\s*(?:\+=|\.(?:append|extend|insert)\b)/m', $source) === 1 || preg_match('/\bALLOWED_HOSTS\s*(?:\+=|\.(?:append|extend|insert)\b)/', $source) === 1) {
                return null;
            }

            if (preg_match_all('/^ALLOWED_HOSTS\s*(?::[^=\n]+)?=\s*(.+)$/m', $source, $matches) > 0) {
                foreach ($matches[1] as $value) {
                    if (preg_replace('/\s+|#.*$/', '', $value) !== '[]') {
                        return null;
                    }
                }

                $empty ??= $path;
            }
        }

        return $empty === null ? null : [
            "{$empty} sets ALLOWED_HOSTS = [], so Django answers 400 Bad Request to every visit on the app's domain.",
            'Read it from the environment, for example ALLOWED_HOSTS = os.environ.get("ALLOWED_HOSTS", "").split(","), then add ALLOWED_HOSTS with your domain to the app\'s environment.',
        ];
    }

    private function settingsModule(RepositoryFacts $facts): ?string
    {
        return preg_match('/DJANGO_SETTINGS_MODULE["\']\s*,\s*["\']([A-Za-z_][\w.]*)["\']/', (string) $facts->file('manage.py'), $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @return array<string, string>
     */
    private function settings(RepositoryFacts $facts, ?string $module): array
    {
        $files = [];

        foreach (self::SETTINGS as $pattern) {
            $files = [...$files, ...$facts->matching($pattern)];
        }

        if ($module === null) {
            return $files;
        }

        $path = str_replace('.', '/', $module);
        $first = array_filter($files, fn(string $file): bool => $file === $path . '.py' || $file === $path . '/__init__.py', ARRAY_FILTER_USE_KEY);

        return [...$first, ...$files];
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function setting(array $settings, string $name): ?string
    {
        foreach ($settings as $source) {
            if (preg_match('/^' . $name . '\s*(?::[^=\n]+)?=\s*(.+)$/m', $source, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function dotted(?string $value): ?string
    {
        if ($value === null || preg_match('/^["\']([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)+)["\']/', $value, $matches) !== 1) {
            return null;
        }

        $at = strrpos($matches[1], '.');

        return substr($matches[1], 0, (int) $at) . ':' . substr($matches[1], (int) $at + 1);
    }

    private function projectOf(int|string|null $settingsFile): ?string
    {
        return is_string($settingsFile) ? explode('/', $settingsFile)[0] : null;
    }
}
