<?php

declare(strict_types=1);

use App\Services\Address\AddressChange;
use App\Services\Address\CaddyAdmin;
use App\Services\Address\SiteFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

function siteRefreshCaddy(?string $refusal = null): object
{
    $caddy = new class($refusal) implements CaddyAdmin
    {
        public int $reloads = 0;

        public function __construct(private readonly ?string $refusal) {}

        public function reload(): void
        {
            $this->reloads++;

            if ($this->refusal !== null && $this->reloads === 1) {
                throw new RuntimeException($this->refusal);
            }
        }

        public function managed(): bool
        {
            return false;
        }
    };

    app()->instance(CaddyAdmin::class, $caddy);

    return $caddy;
}

beforeEach(function () {
    $this->siteFolder = sys_get_temp_dir() . '/coresmith-site-refresh-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->siteFolder);
    $this->site = $this->siteFolder . '/panel.caddy';
    config(['app.url' => 'https://coresmith.example.com', 'coresmith.address.site' => $this->site]);
});

afterEach(fn() => File::deleteDirectory($this->siteFolder));

test("a site written from an older template is rewritten from this release's, and Caddy reloads it", function () {
    file_put_contents($this->site, "coresmith.example.com {\n    an older template\n}\n");
    $caddy = siteRefreshCaddy();

    expect(Artisan::call('coresmith:site-refresh'))->toBe(0)
        ->and(file_get_contents($this->site))->toBe(app(SiteFile::class)->render(['coresmith.example.com']))
        ->and($caddy->reloads)->toBe(1)
        ->and(Artisan::output())->toContain("Caddy now serves coresmith.example.com with this release's site.");
});

test('a site that already matches is left alone, and Caddy is not reloaded', function () {
    file_put_contents($this->site, app(SiteFile::class)->render(['coresmith.example.com']));
    $caddy = siteRefreshCaddy();

    expect(Artisan::call('coresmith:site-refresh'))->toBe(0)
        ->and($caddy->reloads)->toBe(0);
});

test('the redirect from the address Coresmith last moved from is kept', function () {
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');
    AddressChange::finish();
    file_put_contents($this->site, "old\n");
    siteRefreshCaddy();

    Artisan::call('coresmith:site-refresh');

    expect(file_get_contents($this->site))->toBe(app(SiteFile::class)->render(['coresmith.example.com'], ['192.168.0.248' => 'coresmith.example.com']));
});

test('a reload Caddy refuses puts the old site back, reloads that, and says what happened', function () {
    file_put_contents($this->site, "coresmith.example.com {\n    an older template\n}\n");
    $caddy = siteRefreshCaddy('Caddy refused the new configuration: unknown directive');

    expect(Artisan::call('coresmith:site-refresh'))->toBe(1)
        ->and(file_get_contents($this->site))->toBe("coresmith.example.com {\n    an older template\n}\n")
        ->and($caddy->reloads)->toBe(2)
        ->and(Artisan::output())->toContain('Caddy refused the new configuration: unknown directive');
});

test("an install whose Caddy site Coresmith doesn't own is left alone", function () {
    config(['coresmith.address.site' => '']);
    $caddy = siteRefreshCaddy();

    expect(Artisan::call('coresmith:site-refresh'))->toBe(0)
        ->and($caddy->reloads)->toBe(0)
        ->and(file_exists($this->site))->toBeFalse();
});
