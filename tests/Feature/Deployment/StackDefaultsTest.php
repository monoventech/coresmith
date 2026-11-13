<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Deployment\StackDefaults;
use App\Services\Runtime\RepositoryFacts;

function stackDefaultsApp(string $framework, string $env = '', ApplicationType $type = ApplicationType::Php): Application
{
    return new Application(['type' => $type, 'framework' => $framework, 'env_variables' => $env]);
}

test('a new app starts with the variables its framework needs', function (ApplicationType $type, ?string $framework, array $patterns) {
    $initial = app(StackDefaults::class)->initial($type, $framework);

    expect(array_keys($initial))->toBe(array_keys($patterns));

    foreach ($patterns as $name => $pattern) {
        expect($initial[$name])->toMatch($pattern);
    }
})->with([
    'laravel' => [ApplicationType::Php, 'laravel', ['APP_KEY' => '~^base64:[A-Za-z0-9+/]{43}=$~']],
    'symfony' => [ApplicationType::Php, 'symfony', ['APP_ENV' => '~^prod$~', 'APP_SECRET' => '~^[0-9a-f]{32}$~']],
    'rails' => [ApplicationType::Ruby, 'rails', ['SECRET_KEY_BASE' => '~^[0-9a-f]{128}$~']],
    'sinatra' => [ApplicationType::Ruby, 'sinatra', []],
    'plain php' => [ApplicationType::Php, null, []],
    'next.js' => [ApplicationType::Js, 'nextjs', []],
    'static' => [ApplicationType::Static, null, []],
]);

test('each app gets its own secrets', function () {
    $defaults = app(StackDefaults::class);

    expect($defaults->initial(ApplicationType::Php, 'laravel')['APP_KEY'])->not->toBe($defaults->initial(ApplicationType::Php, 'laravel')['APP_KEY']);
});

test('a missing secret is one set neither in the environment nor in the committed .env', function (string $env, array $envKeys, array $missing) {
    expect(array_keys(app(StackDefaults::class)->missing(stackDefaultsApp('laravel', $env), new RepositoryFacts(envKeys: $envKeys, complete: true))))->toBe($missing);
})->with([
    'nowhere' => ['', [], ['APP_KEY']],
    'empty in the environment' => ["APP_KEY=\n", [], ['APP_KEY']],
    'set in the environment' => ["APP_KEY=base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0MTI=\n", [], []],
    'set in the committed .env' => ['', ['APP_KEY'], []],
]);

test('Rails with a master key gets no SECRET_KEY_BASE', function (string $env, RepositoryFacts $facts) {
    expect(app(StackDefaults::class)->missing(stackDefaultsApp('rails', $env, ApplicationType::Ruby), $facts))->toBe([]);
})->with([
    'in the environment' => ["RAILS_MASTER_KEY=0123456789abcdef0123456789abcdef\n", new RepositoryFacts(complete: true)],
    'in the committed .env' => ['', new RepositoryFacts(envKeys: ['RAILS_MASTER_KEY'], complete: true)],
    'committed as config/master.key' => ['', new RepositoryFacts(markers: ['config/master.key'], complete: true)],
]);

test('a deploy fills only secrets, never APP_ENV', function () {
    expect(array_keys(app(StackDefaults::class)->missing(stackDefaultsApp('symfony'), new RepositoryFacts(complete: true))))->toBe(['APP_SECRET']);
});

test('a probe that did not finish fills nothing, because the committed .env could hold the key', function () {
    expect(app(StackDefaults::class)->missing(stackDefaultsApp('laravel'), new RepositoryFacts))->toBe([]);
});
