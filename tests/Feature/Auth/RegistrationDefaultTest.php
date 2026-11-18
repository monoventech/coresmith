<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

function registrationOpenWith(?string $edition, ?string $allow): bool
{
    $keys = ['CORESMITH_EDITION' => $edition, 'CORESMITH_ALLOW_REGISTRATION' => $allow];
    $saved = [];

    foreach ($keys as $key => $value) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);

        if ($value !== null) {
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        return in_array(Features::registration(), (require config_path('fortify.php'))['features'], true);
    } finally {
        foreach ($saved as $key => [$server, $env, $put]) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }

            if ($env !== null) {
                $_ENV[$key] = $env;
            }

            if ($put !== false) {
                putenv("{$key}={$put}");
            }
        }
    }
}

test('sign-up is open by default only on Coresmith Cloud, and the setting wins either way', function (?string $edition, ?string $allow, bool $open) {
    expect(registrationOpenWith($edition, $allow))->toBe($open);
})->with([
    'self-hosted by default' => [null, null, false],
    'self-hosted' => ['self-hosted', null, false],
    'cloud' => ['cloud', null, true],
    'self-hosted, opened on purpose' => ['self-hosted', 'true', true],
    'cloud, closed on purpose' => ['cloud', 'false', false],
]);
