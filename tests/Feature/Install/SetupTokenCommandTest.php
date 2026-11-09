<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Install\SetupToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->setupFolder = sys_get_temp_dir() . '/coresmith-setup-link-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->setupFolder);
    config(['app.url' => 'https://coresmith.example.com', 'coresmith.setup_token_path' => "{$this->setupFolder}/setup-token"]);
});

afterEach(fn() => File::deleteDirectory($this->setupFolder));

test('issuing a setup token prints the link to open, so nobody pastes a token into an address', function () {
    expect(Artisan::call('coresmith:setup-token', ['--force' => true]))->toBe(0);

    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    expect($lines[0])->toBe('Open this link to create the owner account. It works once:')
        ->and($lines[1])->toMatch('#^https://coresmith\.example\.com/install\?token=[A-Za-z0-9]{64}$#')
        ->and(app(SetupToken::class)->matches(substr($lines[1], -64)))->toBeTrue();
});

test('once the owner account exists, no link is issued', function () {
    User::factory()->create();

    expect(Artisan::call('coresmith:setup-token', ['--force' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('This instance already has an account. Setup is closed.')
        ->and(Artisan::output())->not->toContain('/install?token=');
});
