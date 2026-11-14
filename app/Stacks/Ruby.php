<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\Stack;

final readonly class Ruby implements Language
{
    public function __construct(private JsCommands $js) {}

    public function install(Application $application, Stack $stack): array
    {
        return ['bundle config set --local deployment true', "bundle config set --local without 'development test'", 'bundle install', ...$this->js->install($stack)];
    }

    public function build(Application $application, Stack $stack): array
    {
        if ($application->framework === 'sinatra' || ($stack->locked('sprockets-rails') === false && $stack->locked('propshaft') === false)) {
            return [];
        }

        return ['RAILS_ENV=production bundle exec rails assets:precompile'];
    }

    public function migrations(Application $application, Stack $stack): array
    {
        if ($application->framework === 'sinatra') {
            return $stack->locked('sinatra-activerecord') === true && $stack->present('Rakefile') === true ? ['RACK_ENV=production bundle exec rake db:migrate'] : [];
        }

        return $stack->present('config/database.yml') === false ? [] : ['RAILS_ENV=production bundle exec rails db:migrate'];
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        return ['Gemfile' => 'test -f ' . escapeshellarg($paths->appDirectory($release) . '/Gemfile')];
    }
}
