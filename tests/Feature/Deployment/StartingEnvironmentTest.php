<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DatabaseType;
use App\Services\Deployment\StartingEnvironment;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;

function startingFacts(array $files): RepositoryFacts
{
    return new RepositoryFacts($files, complete: true);
}

function startingLaravelExample(): string
{
    return implode("\n", [
        'APP_NAME=Laravel',
        'APP_ENV=local',
        'APP_KEY=',
        'APP_DEBUG=true',
        'APP_URL=http://localhost',
        '',
        'LOG_CHANNEL=stack',
        'DB_CONNECTION=sqlite',
        '# DB_HOST=127.0.0.1',
        '# DB_PORT=3306',
        'SESSION_DRIVER=database',
        'CACHE_STORE=database',
        'QUEUE_CONNECTION=database',
        'REDIS_HOST=127.0.0.1',
        'REDIS_PASSWORD=null',
        'REDIS_PORT=6379',
        'MAIL_MAILER=log',
        'MAIL_PASSWORD=null',
        'VITE_APP_NAME="${APP_NAME}"',
        'REVERB_HOST="localhost"',
        'REVERB_PORT=8080',
    ]) . "\n";
}

test('a Laravel app without an example file starts with its name, its secret and the settings production needs', function () {
    $variables = app(StartingEnvironment::class)->variables(startingFacts([]), ApplicationType::Php, 'laravel', 'shop.example.com', 'Shop');

    expect(array_keys($variables))->toBe(['APP_NAME', 'LOG_CHANNEL', 'LOG_LEVEL', 'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL'])
        ->and($variables)->toMatchArray(['APP_NAME' => 'Shop', 'LOG_CHANNEL' => 'stack', 'LOG_LEVEL' => 'error', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://shop.example.com'])
        ->and($variables['APP_KEY'])->toStartWith('base64:');
});

test('another stack without an example file starts with only its generated secret, as before', function () {
    $variables = app(StartingEnvironment::class)->variables(startingFacts([]), ApplicationType::Ruby, 'rails', 'shop.example.com', 'Shop');

    expect(array_keys($variables))->toBe(['SECRET_KEY_BASE']);
});

test('a Laravel app starts from its .env.example, set for production, with its connection and Reverb settings left to Coresmith', function () {
    $variables = app(StartingEnvironment::class)->variables(startingFacts(['.env.example' => startingLaravelExample()]), ApplicationType::Php, 'laravel', 'shop.example.com');

    expect(array_keys($variables))->toBe(['APP_NAME', 'APP_KEY', 'APP_URL', 'LOG_CHANNEL', 'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION', 'MAIL_MAILER', 'MAIL_PASSWORD', 'VITE_APP_NAME', 'APP_ENV', 'APP_DEBUG'])
        ->and($variables['APP_ENV'])->toBe('production')
        ->and($variables['APP_DEBUG'])->toBe('false')
        ->and($variables['APP_URL'])->toBe('https://shop.example.com')
        ->and($variables['APP_KEY'])->toStartWith('base64:')
        ->and($variables['MAIL_PASSWORD'])->toBe('')
        ->and($variables['VITE_APP_NAME'])->toBe('Laravel');
});

test('a secret the example commits is never carried into production, and a placeholder arrives empty', function () {
    $example = "APP_KEY=base64:c29tZWJvZHkgY29tbWl0dGVkIHRoaXMga2V5IGFsbCB5ZWFyIQ==\nSTRIPE_SECRET=sk_test_placeholder\nAWS_ACCESS_KEY_ID=AKIAEXAMPLE\nKEY_PREFIX=shop\n";
    $variables = app(StartingEnvironment::class)->variables(startingFacts(['.env.example' => $example]), ApplicationType::Php, 'laravel', null);

    expect($variables['APP_KEY'])->not->toBe('base64:c29tZWJvZHkgY29tbWl0dGVkIHRoaXMga2V5IGFsbCB5ZWFyIQ==')
        ->and($variables['STRIPE_SECRET'])->toBe('')
        ->and($variables['AWS_ACCESS_KEY_ID'])->toBe('')
        ->and($variables['KEY_PREFIX'])->toBe('shop')
        ->and($variables)->not->toHaveKey('APP_URL');
});

test('a run mode, port or host from the example never overrides what Coresmith sets for the stack', function () {
    $example = "NODE_ENV=development\nPORT=3000\nHOST=0.0.0.0\nHOSTNAME=localhost\nDEBUG=true\nAPI_BASE=https://api.example.com\n";

    expect(app(StartingEnvironment::class)->variables(startingFacts(['.env.example' => $example]), ApplicationType::Js, null, null))->toBe(['API_BASE' => 'https://api.example.com'])
        ->and(app(StartingEnvironment::class)->variables(startingFacts(['.env.example' => "RAILS_ENV=development\nRAILS_MAX_THREADS=5\n"]), ApplicationType::Ruby, 'rails', null))->toHaveKeys(['RAILS_MAX_THREADS', 'SECRET_KEY_BASE'])
        ->not->toHaveKey('RAILS_ENV');
});

test('the first example file found is used, in the order .env.example, .env.sample, .env.dist, .env.template', function () {
    $starting = app(StartingEnvironment::class);

    expect($starting->example(startingFacts(['.env.template' => "A=1\n", '.env.sample' => "B=2\n"])))->toBe('.env.sample')
        ->and($starting->variables(startingFacts(['.env.dist' => "NODE_OPTIONS=--max-old-space-size=512\n"]), ApplicationType::Js, null, null))->toBe(['NODE_OPTIONS' => '--max-old-space-size=512']);
});

test('the example says which database and whether Redis the app expects', function (string $example, ?DatabaseType $database, bool $redis) {
    $facts = startingFacts(['.env.example' => $example]);
    $starting = app(StartingEnvironment::class);

    expect($starting->database($facts))->toBe($database)
        ->and($starting->wantsRedis($facts))->toBe($redis);
})->with([
    'PostgreSQL' => ["DB_CONNECTION=pgsql\n", DatabaseType::Postgresql, false],
    'MySQL with a Redis queue' => ["DB_CONNECTION=mysql\nQUEUE_CONNECTION=redis\n", DatabaseType::Mysql, true],
    'MariaDB with a Redis cache' => ["DB_CONNECTION=mariadb\nCACHE_STORE=redis\n", DatabaseType::Mysql, true],
    'SQLite with Redis settings it does not use' => ["DB_CONNECTION=sqlite\nREDIS_HOST=127.0.0.1\n", null, false],
    'nothing' => ["APP_NAME=Shop\n", null, false],
]);

test('the repository read collects the example files', function () {
    $output = '@@file .env.example ' . base64_encode("APP_NAME=Shop\n") . "\n@@done\n";

    expect(app(RepositoryProbe::class)->parse($output)->file('.env.example'))->toBe("APP_NAME=Shop\n")
        ->and(RepositoryProbe::FILES)->toContain('.env.sample', '.env.dist', '.env.template');
});
