<?php

declare(strict_types=1);

use App\Services\Address\EnvironmentFile;
use App\Services\Address\SiteFile;
use Illuminate\Support\Facades\File;

function siteFileTemp(): string
{
    $directory = sys_get_temp_dir() . '/coresmith-site-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($directory);

    return $directory;
}

test('the site answers on every address it is given, the same way the installer writes it', function () {
    $site = app(SiteFile::class)->render(['192.168.0.248', 'coresmith.example.com']);

    expect($site)->toStartWith("192.168.0.248, coresmith.example.com {\n    root * /opt/coresmith/app/public\n")
        ->and($site)->toContain("http://127.0.0.1:8484 {\n")
        ->and($site)->toContain("https:// {\n    tls {\n        on_demand\n    }")
        ->and($site)->not->toContain('__');
});

test('an old address sends people on to the new one', function () {
    $site = app(SiteFile::class)->render(['coresmith.example.com'], ['192.168.0.248' => 'coresmith.example.com']);

    expect($site)->toStartWith("coresmith.example.com {\n")
        ->and($site)->toEndWith("\n192.168.0.248 {\n    redir https://coresmith.example.com{uri} permanent\n}\n");
});

test('an ipv6 address is written the way caddy reads one', function () {
    $site = app(SiteFile::class)->render(['fd00::248'], ['coresmith.example.com' => 'fd00::248']);

    expect($site)->toStartWith("[fd00::248] {\n")
        ->and($site)->toContain("coresmith.example.com {\n    redir https://[fd00::248]{uri} permanent\n}\n");
});

test('the site file is replaced whole, readable by caddy and nobody else', function () {
    $directory = siteFileTemp();
    config(['coresmith.address.site' => "{$directory}/panel.caddy"]);
    file_put_contents("{$directory}/panel.caddy", "old {\n}\n");

    try {
        app(SiteFile::class)->write(['coresmith.example.com']);

        expect(file_get_contents("{$directory}/panel.caddy"))->toStartWith("coresmith.example.com {\n")
            ->and(fileperms("{$directory}/panel.caddy") & 0777)->toBe(0640)
            ->and(glob("{$directory}/*"))->toBe(["{$directory}/panel.caddy"]);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('the environment file keeps everything it does not change, through the link releases use', function () {
    $directory = siteFileTemp();
    file_put_contents("{$directory}/shared.env", "APP_NAME=Coresmith\nAPP_URL=https://192.168.0.248\nREVERB_APP_SECRET=\"keep me\"\n");
    chmod("{$directory}/shared.env", 0600);
    symlink("{$directory}/shared.env", "{$directory}/.env");

    try {
        (new EnvironmentFile("{$directory}/.env"))->set(['APP_URL' => 'https://coresmith.example.com', 'VITE_REVERB_HOST' => 'coresmith.example.com']);

        expect(file_get_contents("{$directory}/shared.env"))->toBe("APP_NAME=Coresmith\nAPP_URL=https://coresmith.example.com\nREVERB_APP_SECRET=\"keep me\"\nVITE_REVERB_HOST=coresmith.example.com\n")
            ->and(is_link("{$directory}/.env"))->toBeTrue()
            ->and(fileperms("{$directory}/shared.env") & 0777)->toBe(0600);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('a value the environment file cannot hold safely is refused', function () {
    (new EnvironmentFile(sys_get_temp_dir() . '/never-written.env'))->set(['APP_URL' => "https://x\nAPP_KEY=stolen"]);
})->throws(InvalidArgumentException::class);
