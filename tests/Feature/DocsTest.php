<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

function docsReadme(): string
{
    return (string) file_get_contents(base_path('README.md'));
}

test('the readme has no em dash', function () {
    expect(docsReadme())->not->toContain('—');
});

test('every artisan command the readme names exists', function () {
    preg_match_all('/php artisan ([a-z][a-z0-9:-]*)/', docsReadme(), $matches);
    $commands = array_values(array_unique($matches[1]));

    expect($commands)->toContain('coresmith:update', 'coresmith:uninstall', 'coresmith:billing-check');

    foreach ($commands as $command) {
        expect(Artisan::all())->toHaveKey($command);
    }
});

test('every installer option the readme names is one the installer takes', function () {
    $help = (new Process(['bash', base_path('scripts/install.sh'), '--help']))->mustRun()->getOutput();

    preg_match_all('/`(--[a-z][a-z-]*)/', docsReadme(), $matches);
    $options = array_values(array_unique($matches[1]));

    expect($options)->toContain('--domain', '--db', '--version', '--unattended');

    foreach ($options as $option) {
        expect($help)->toMatch('/^\s*' . preg_quote($option, '/') . '\b/m');
    }
});
