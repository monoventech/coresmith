<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\Stack;

/**
 * @param  array<string, bool>  $gems
 */
function rubyPipelineStack(array $gems, ?array $files = ['Gemfile', 'Gemfile.lock', 'config.ru']): Stack
{
    return new Stack(gems: $gems, files: $files);
}

function rubyPipelineApp(string $framework): Application
{
    return new Application(['type' => ApplicationType::Ruby, 'framework' => $framework, 'run_migrations' => true]);
}

test('a Sinatra app builds without rails and has nothing to migrate on its own', function () {
    $stack = rubyPipelineStack(['sprockets-rails' => false, 'propshaft' => false, 'sinatra-activerecord' => false]);

    expect(app(DeploymentCommands::class)->build(rubyPipelineApp('sinatra'), $stack))->toBe([])
        ->and(app(DeploymentCommands::class)->migrations(rubyPipelineApp('sinatra'), $stack))->toBe([]);
});

test('a Sinatra app with sinatra-activerecord and a Rakefile migrates through rake', function () {
    $stack = rubyPipelineStack(['sinatra-activerecord' => true], ['Gemfile', 'Gemfile.lock', 'config.ru', 'Rakefile']);

    expect(app(DeploymentCommands::class)->migrations(rubyPipelineApp('sinatra'), $stack))->toBe(['RACK_ENV=production bundle exec rake db:migrate'])
        ->and(app(DeploymentCommands::class)->migrations(rubyPipelineApp('sinatra'), rubyPipelineStack(['sinatra-activerecord' => true])))->toBe([]);
});

test('a Rails app precompiles assets only when it has an asset pipeline', function (array $gems, ?array $files, array $build) {
    expect(app(DeploymentCommands::class)->build(rubyPipelineApp('rails'), rubyPipelineStack($gems, $files)))->toBe($build);
})->with([
    'propshaft' => [['sprockets-rails' => false, 'propshaft' => true], ['Gemfile', 'Gemfile.lock'], ['RAILS_ENV=production bundle exec rails assets:precompile']],
    'sprockets' => [['sprockets-rails' => true, 'propshaft' => false], ['Gemfile', 'Gemfile.lock'], ['RAILS_ENV=production bundle exec rails assets:precompile']],
    'api only' => [['sprockets-rails' => false, 'propshaft' => false], ['Gemfile', 'Gemfile.lock'], []],
    'a stack saved before Coresmith tracked the asset gems' => [['sidekiq' => false], null, ['RAILS_ENV=production bundle exec rails assets:precompile']],
]);

test('a Rails app migrates with rails when it has a database configuration, or Coresmith did not see its files', function (?array $files) {
    expect(app(DeploymentCommands::class)->migrations(rubyPipelineApp('rails'), rubyPipelineStack(['propshaft' => true], $files)))->toBe(['RAILS_ENV=production bundle exec rails db:migrate']);
})->with([
    'config/database.yml' => [['Gemfile', 'Gemfile.lock', 'config.ru', 'config/database.yml']],
    'files not read' => [null],
]);

test('a Rails app without config/database.yml has no Active Record to migrate, so it skips migrations', function () {
    expect(app(DeploymentCommands::class)->migrations(rubyPipelineApp('rails'), rubyPipelineStack(['propshaft' => true])))->toBe([]);
});
