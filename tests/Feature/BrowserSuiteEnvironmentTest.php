<?php

declare(strict_types=1);

use Dotenv\Dotenv;

test('a saved browser sign-in outlives the longest browser run', function (string $file) {
    $values = Dotenv::parse((string) file_get_contents(base_path($file)));

    expect((int) ($values['SESSION_LIFETIME'] ?? 0))->toBeGreaterThanOrEqual(720);
})->with(['.env.e2e', '.env.e2e-cloud', '.env.e2e-install']);
