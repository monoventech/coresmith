<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Detection\DetectedStack;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\Stack;

function rackFacts(string $gemfile, string $specs = '', array $markers = ['config.ru']): RepositoryFacts
{
    $lock = $specs === '' ? [] : ['Gemfile.lock' => "GEM\n  remote: https://rubygems.org/\n  specs:\n{$specs}\nPLATFORMS\n  ruby\n\nBUNDLED WITH\n   2.6.9\n"];

    return new RepositoryFacts(['Gemfile' => $gemfile, ...$lock], markers: $markers, complete: true);
}

function rackApp(): Application
{
    return new Application(['type' => ApplicationType::Ruby, 'framework' => 'rack', 'run_migrations' => true, 'slug' => 'shelf', 'base_port' => 4200, 'ruby_version' => '3.4.7']);
}

test('a config.ru app whose Gemfile names neither rails nor sinatra runs as a Rack app, naming what it found', function (RepositoryFacts $facts, string $evidence) {
    $proposal = ApplicationType::Ruby->language()->detect($facts);

    expect($proposal['type'])->toBe('ruby')
        ->and($proposal['framework'])->toBe('rack')
        ->and($proposal['sources']['framework'])->toBe($evidence)
        ->and($proposal['unsettled'])->toBe([]);
})->with([
    'Hanami, locked' => [fn(): RepositoryFacts => rackFacts("gem 'hanami', '~> 2.2'\ngem 'puma'\n", "    hanami (2.2.1)\n    puma (6.6.0)"), 'config.ru, with hanami 2.2.1 in Gemfile.lock'],
    'Roda, locked' => [fn(): RepositoryFacts => rackFacts("gem 'roda'\ngem 'puma'\n", "    puma (6.6.0)\n    roda (3.86.0)"), 'config.ru, with roda 3.86.0 in Gemfile.lock'],
    'Grape, Gemfile only' => [fn(): RepositoryFacts => rackFacts("gem 'grape'\n"), 'config.ru, with grape in Gemfile'],
    'plain Rack' => [fn(): RepositoryFacts => rackFacts("gem 'rack'\ngem 'puma'\n", "    puma (6.6.0)\n    rack (3.1.8)"), "config.ru at the repository's root"],
]);

test('a Gemfile with neither rails nor sinatra and no config.ru leaves the framework open and says what to add', function () {
    $proposal = ApplicationType::Ruby->language()->detect(rackFacts("gem 'roda'\n", markers: []));

    expect($proposal['framework'])->toBeNull()
        ->and($proposal['unsettled'])->toBe(['framework' => "Gemfile names neither rails nor sinatra, and there is no config.ru, so Coresmith can't tell how to start the app. Add a config.ru that runs it."]);
});

test('a Rack app installs with Bundler, builds and migrates nothing, and runs on Puma with no Rails settings', function () {
    $commands = app(DeploymentCommands::class);
    $stack = new Stack(gems: ['sprockets-rails' => true, 'propshaft' => false, 'sinatra-activerecord' => false], files: ['Gemfile', 'Gemfile.lock', 'config.ru', 'Rakefile', 'config/database.yml']);

    expect($commands->dependencies(rackApp(), $stack))->toBe(['bundle config set --local deployment true', "bundle config set --local without 'development test'", 'bundle install'])
        ->and($commands->build(rackApp(), $stack))->toBe([])
        ->and($commands->migrations(rackApp(), $stack))->toBe([])
        ->and(ApplicationType::Ruby->language()->webCommand(rackApp()))->toBe('/opt/asdf-data/installs/ruby/3.4.7/bin/bundle exec puma -b tcp://127.0.0.1:4200')
        ->and(ApplicationType::Ruby->language()->processEnvironment(rackApp()))->toBe(['RAILS_ENV' => 'production', 'RACK_ENV' => 'production'])
        ->and(ApplicationType::Ruby->language()->initialEnvironment('rack'))->toBe([]);
});

test('a Rack app without config.ru or Puma is blocked with the fix for each', function () {
    $blockers = ApplicationType::Ruby->language()->checks(rackApp(), 'rack', rackFacts("gem 'roda'\n", '    roda (3.86.0)', markers: []), null);

    expect(array_map(fn($blocker): array => [$blocker->field, $blocker->reason, $blocker->fix], $blockers))->toBe([
        ['repository', "Puma isn't in Gemfile.lock. Coresmith starts Ruby apps with Puma.", 'Add gem "puma" to the Gemfile, run bundle install and commit Gemfile.lock.'],
        ['framework', 'There is no config.ru, so Puma has no app to start.', 'Add a config.ru that runs the app, for example: run Hanami.app.'],
    ]);
});

test('a Rack app is called Rack where its stack is named', function () {
    $detected = new DetectedStack('ruby', 'rack', null, null);

    expect($detected->label(app(Catalog::class)))->toBe('Rack')
        ->and(ApplicationPresenter::kind(rackApp()))->toBe('Rack');
});
