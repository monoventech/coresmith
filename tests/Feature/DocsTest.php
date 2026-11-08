<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Services\Billing\BillingPresenter;
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

    expect($commands)->toContain('coresmith:setup-token', 'coresmith:update', 'coresmith:uninstall');

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

test('every github app setting is in the example environment', function (string $setting) {
    expect((string) file_get_contents(base_path('.env.example')))->toMatch('/^' . $setting . '=$/m');
})->with(['GITHUB_APP_ID', 'GITHUB_APP_SLUG', 'GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET', 'GITHUB_APP_PRIVATE_KEY', 'GITHUB_APP_WEBHOOK_SECRET']);

test('the readme has no instructions for running coresmith cloud', function () {
    expect(docsReadme())
        ->not->toContain('CORESMITH_EDITION')
        ->not->toContain('STRIPE_')
        ->not->toContain('cashier:webhook')
        ->not->toContain('GITHUB_APP_');
});

test('the readme has the self-hosting section the billing page links to', function () {
    expect(docsReadme())->toContain("\n## Self-hosting\n")
        ->and((new ReflectionClassConstant(BillingPresenter::class, 'SELF_HOSTING_URL'))->getValue())->toBe('https://github.com/monoventech/coresmith#self-hosting');
});

test('the readme prices every cloud plan as the panel does', function (Plan $plan) {
    expect(docsReadme())->toContain("| {$plan->label()} | \${$plan->amount()} a month |");
})->with(Plan::purchasable());

test('the readme gives the cloud trial the length the panel does', function () {
    $days = (int) config('coresmith.billing.trial_days');

    expect(docsReadme())->toContain("| Trial | Free for {$days} days, no card |")
        ->toContain("{$days}-day free trial");
});

test('an example environment with the github app settings left empty configures no github app', function () {
    config(['services.github' => ['app_id' => '', 'app_slug' => '', 'client_id' => '', 'client_secret' => '', 'private_key' => '', 'webhook_secret' => '']]);

    expect(app(GitHubApps::class)->env())->toBeNull();
});

test("the readme says apps go on servers other than the panel's own", function () {
    expect(docsReadme())->toContain("Run the panel on a server of its own. Coresmith can't provision the server the panel runs on, so add other servers for your apps.");
});
