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

final class Starlette extends PythonPreset
{
    private const ROOT = ['main.py', 'app.py', 'asgi.py', 'server.py', 'api.py'];

    private const NESTED = ['main.py', 'app.py', 'asgi.py', '__init__.py', 'api.py', 'server.py'];

    private const FACTORIES = ['create_app', 'get_application', 'get_app'];

    public function key(): string
    {
        return 'starlette';
    }

    public function label(): string
    {
        return 'Starlette';
    }

    public function package(): string
    {
        return 'starlette';
    }

    public function app(RepositoryFacts $facts, PythonDependencies $dependencies): PythonApp
    {
        $module = PythonSource::find(PythonSource::candidates($facts, self::ROOT, self::NESTED), ['Starlette', ...self::FACTORIES], self::FACTORIES);

        return $module === null
            ? PythonApp::missing('uvicorn', "Coresmith found no app = Starlette() in main.py, app.py or a package's main.py, so it can't tell what uvicorn should load.", 'Make the app in main.py, for example app = Starlette().')
            : new PythonApp('uvicorn', $module, migrate: $this->alembic($facts, $dependencies));
    }
}
