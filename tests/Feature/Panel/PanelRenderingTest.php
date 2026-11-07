<?php

declare(strict_types=1);

function panelRenderingDefaults(): array
{
    $saved = [$_ENV['INERTIA_SSR_ENABLED'] ?? null, $_SERVER['INERTIA_SSR_ENABLED'] ?? null, getenv('INERTIA_SSR_ENABLED')];
    unset($_ENV['INERTIA_SSR_ENABLED'], $_SERVER['INERTIA_SSR_ENABLED']);
    putenv('INERTIA_SSR_ENABLED');

    try {
        return require base_path('config/inertia.php');
    } finally {
        [$env, $server, $process] = $saved;

        if ($env !== null) {
            $_ENV['INERTIA_SSR_ENABLED'] = $env;
        }

        if ($server !== null) {
            $_SERVER['INERTIA_SSR_ENABLED'] = $server;
        }

        if ($process !== false) {
            putenv("INERTIA_SSR_ENABLED={$process}");
        }
    }
}

test('the panel renders on the client unless told otherwise', function () {
    expect(panelRenderingDefaults()['ssr']['enabled'])->toBeFalse();
});

test('composer installs and updates build the client bundle only', function () {
    $scripts = json_decode((string) file_get_contents(base_path('composer.json')), true)['scripts'];

    foreach (['post-install-cmd', 'post-update-cmd'] as $event) {
        expect(implode("\n", $scripts[$event]))->toContain('bun run build')->not->toContain('build:ssr');
    }

    expect($scripts)->not->toHaveKey('dev:ssr');
});

test('the installer builds the client bundle only', function () {
    expect((string) file_get_contents(base_path('scripts/install.sh')))->toContain('bun run build')->not->toContain('build:ssr');
});
