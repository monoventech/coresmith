<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\PythonPresets;

use App\Services\Runtime\RepositoryFacts;
use App\Stacks\PythonApp;
use App\Stacks\PythonDependencies;
use App\Stacks\PythonSource;

final class Flask extends PythonPreset
{
    private const ROOT = ['app.py', 'wsgi.py', 'application.py', 'main.py', 'server.py', 'run.py'];

    private const NESTED = ['__init__.py', 'app.py', 'wsgi.py', 'main.py'];

    public function key(): string
    {
        return 'flask';
    }

    public function label(): string
    {
        return 'Flask';
    }

    public function package(): string
    {
        return 'flask';
    }

    public function app(RepositoryFacts $facts, PythonDependencies $dependencies): PythonApp
    {
        $module = PythonSource::find(PythonSource::candidates($facts, self::ROOT, self::NESTED), ['Flask', 'create_app', 'make_app'], ['create_app', 'make_app']);

        return $module === null
            ? PythonApp::missing('gunicorn', "Coresmith found no app = Flask(...) or create_app() in app.py, wsgi.py or a package's __init__.py, so it can't tell what gunicorn should load.", 'Add a wsgi.py that makes the app, for example app = create_app().')
            : new PythonApp('gunicorn', $module, migrate: $this->alembic($facts, $dependencies));
    }

    public function initialEnvironment(): array
    {
        return ['SECRET_KEY' => bin2hex(random_bytes(32))];
    }
}
