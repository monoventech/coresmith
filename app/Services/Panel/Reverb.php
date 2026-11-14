<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Application;
use App\Services\Deployment\EnvironmentFile;

final class Reverb
{
    public const PORT_OFFSET = 2;

    public const ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}\z/D';

    public const COMMAND = 'php artisan reverb:start --host=127.0.0.1 --port=';

    public const NO_SETTINGS = 'Set REVERB_APP_ID, REVERB_APP_KEY and REVERB_APP_SECRET in the environment to turn it on';

    public const BAD_CHARACTERS = 'REVERB_APP_ID and REVERB_APP_KEY can only use letters, numbers, dots, dashes and underscores';

    public const NO_DOMAIN = 'Add a domain first. Browsers reach Reverb through it';

    public const NOT_INSTALLED = 'composer.json no longer requires laravel/reverb, so it does not start';

    public function __construct(private readonly EnvironmentFile $environment) {}

    public function port(Application $application): int
    {
        return (int) $application->base_port + self::PORT_OFFSET;
    }

    /**
     * @return array{id: string, key: string, secret: string}|null
     */
    public function credentials(Application $application): ?array
    {
        $variables = $this->environment->parse((string) $application->env_variables);
        $id = trim($variables['REVERB_APP_ID'] ?? '');
        $key = trim($variables['REVERB_APP_KEY'] ?? '');
        $secret = trim($variables['REVERB_APP_SECRET'] ?? '');

        return $id === '' || $key === '' || $secret === '' ? null : ['id' => $id, 'key' => $key, 'secret' => $secret];
    }

    public function problem(Application $application): ?string
    {
        $credentials = $this->credentials($application);

        return match (true) {
            $credentials === null => self::NO_SETTINGS,
            preg_match(self::ID_PATTERN, $credentials['id']) !== 1 || preg_match(self::ID_PATTERN, $credentials['key']) !== 1 => self::BAD_CHARACTERS,
            blank($application->domain) => self::NO_DOMAIN,
            default => null,
        };
    }

    public function on(Application $application): bool
    {
        return (((array) $application->toggles)['reverb'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>|null  $stack
     */
    public function ready(Application $application, ?array $stack = null): bool
    {
        return $this->on($application) && $this->installed($stack ?? (array) $application->stack) && $this->problem($application) === null;
    }

    /**
     * @param  array<string, mixed>  $stack
     */
    public function installed(array $stack): bool
    {
        return (((array) ($stack['packages'] ?? []))['reverb'] ?? false) === true;
    }

    public function live(Application $application): bool
    {
        return $this->on($application) && collect((array) $application->processes)->contains(fn(mixed $process): bool => is_array($process) && ($process['name'] ?? null) === 'reverb');
    }
}
