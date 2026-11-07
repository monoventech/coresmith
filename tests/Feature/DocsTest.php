<?php

declare(strict_types=1);

use App\Services\Git\GitHub\GitHubApps;
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

test('every github app setting is in the example environment and the readme', function (string $setting) {
    expect((string) file_get_contents(base_path('.env.example')))->toMatch('/^' . $setting . '=$/m')
        ->and(docsReadme())->toContain('`' . $setting . '`');
})->with(['GITHUB_APP_ID', 'GITHUB_APP_SLUG', 'GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET', 'GITHUB_APP_PRIVATE_KEY', 'GITHUB_APP_WEBHOOK_SECRET']);

test('the readme tells an operator how to create the github app', function () {
    expect(docsReadme())
        ->toContain('/settings/source-providers/github_app/callback')
        ->toContain('/api/webhooks/github')
        ->toContain('Request user authorization (OAuth) during installation')
        ->toContain('Contents read-only, Metadata read-only and Commit statuses read and write')
        ->toContain('the Push event');
});

test('an example environment with the github app settings left empty configures no github app', function () {
    config(['services.github' => ['app_id' => '', 'app_slug' => '', 'client_id' => '', 'client_secret' => '', 'private_key' => '', 'webhook_secret' => '']]);

    expect(app(GitHubApps::class)->env())->toBeNull();
});

test("the readme says apps go on servers other than the panel's own", function () {
    expect(docsReadme())->toContain("Run the panel on a server of its own. Coresmith can't provision the server the panel runs on, so add other servers for your apps.");
});
