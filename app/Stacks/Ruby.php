<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Detection\Blocker;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\NativeGemTools;
use App\Services\Runtime\NativeTools;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use Illuminate\Support\Arr;

final readonly class Ruby implements Language
{
    public const GEM = 'Gemfile loads a gemspec, so this looks like a Ruby gem, not an app.';

    public const NO_CONFIG_RU = "Gemfile names neither rails nor sinatra, and there is no config.ru, so Coresmith can't tell how to start the app. Add a config.ru that runs it.";

    private const RACK_FRAMEWORKS = ['hanami', 'roda', 'grape'];

    public function __construct(private JsCommands $js, private AsdfManager $asdf) {}

    public function install(Application $application, Stack $stack): array
    {
        return ['bundle config set --local deployment true', "bundle config set --local without 'development test'", 'bundle install', ...$this->js->install($stack)];
    }

    public function build(Application $application, Stack $stack): array
    {
        if (in_array($application->framework, ['sinatra', 'rack'], true) || ($stack->locked('sprockets-rails') === false && $stack->locked('propshaft') === false)) {
            return [];
        }

        return ['RAILS_ENV=production bundle exec rails assets:precompile'];
    }

    public function migrations(Application $application, Stack $stack): array
    {
        return match ($application->framework) {
            'sinatra' => $stack->locked('sinatra-activerecord') === true && $stack->present('Rakefile') === true ? ['RACK_ENV=production bundle exec rake db:migrate'] : [],
            'rack' => [],
            default => $stack->present('config/database.yml') === false ? [] : ['RAILS_ENV=production bundle exec rails db:migrate'],
        };
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        return ['Gemfile' => 'test -f ' . escapeshellarg($paths->appDirectory($release) . '/Gemfile')];
    }

    public function webCommand(Application $application): ?string
    {
        return $this->asdf->binaryPath('ruby', (string) $application->ruby_version, 'bundle') . ' exec puma -b tcp://127.0.0.1:' . $application->base_port;
    }

    public function usesFpm(): bool
    {
        return false;
    }

    public function route(Application $application): Route
    {
        return Route::ReverseProxy;
    }

    public function storageSeed(): StorageSeed
    {
        return StorageSeed::FromLiveRelease;
    }

    public function initialEnvironment(?string $framework): array
    {
        return $framework === 'rails' ? ['SECRET_KEY_BASE' => bin2hex(random_bytes(64))] : [];
    }

    public function processEnvironment(Application $application): array
    {
        return [
            'RAILS_ENV' => 'production',
            'RACK_ENV' => 'production',
            ...(in_array(strtolower((string) $application->framework), ['sinatra', 'rack'], true) ? [] : ['RAILS_SERVE_STATIC_FILES' => '1', 'RAILS_LOG_TO_STDOUT' => '1']),
        ];
    }

    public function listenEnvironment(Application $application): array
    {
        return ['PORT' => (string) $application->base_port, 'HOST' => '127.0.0.1'];
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>, fields?: array<string, ?string>, options?: array<string, list<string>>}|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        if (!$facts->has('Gemfile')) {
            return null;
        }

        if (preg_match('/^\s*gemspec\b/m', (string) $facts->file('Gemfile')) === 1) {
            return Proposal::unsure('type', self::GEM);
        }

        $sources = ['type' => $facts->has('Gemfile.lock') ? 'From Gemfile and Gemfile.lock' : 'From Gemfile'];

        foreach (['rails' => 'bin/rails', 'sinatra' => 'config.ru'] as $gem => $marker) {
            $version = $facts->gem($gem);

            if ($version === null && !$this->gemfileNames($facts, $gem)) {
                continue;
            }

            if ($gem === 'rails' && !$facts->exists($marker)) {
                return Proposal::make('ruby', null, null, null, $sources, ['framework' => 'Gemfile names rails, but there is no bin/rails, so this looks like a Rails engine or gem, not an app.']);
            }

            return Proposal::make('ruby', $gem, null, null, [...$sources, 'framework' => $version === null ? "{$gem} in Gemfile" : "{$gem} {$version} in Gemfile.lock"], $this->oldBundler($facts));
        }

        if ($facts->exists('config.ru')) {
            return Proposal::make('ruby', 'rack', null, null, [...$sources, 'framework' => $this->rackSource($facts)], $this->oldBundler($facts));
        }

        return Proposal::make('ruby', null, null, null, $sources, ['framework' => self::NO_CONFIG_RU]);
    }

    /**
     * @return list<Blocker>
     */
    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        if (!$facts->has('Gemfile')) {
            return [new Blocker('type', "There is no Gemfile {$facts->at()}, so this isn't a Ruby app.", 'Pick the language the repository uses.')];
        }

        if (!$facts->has('Gemfile.lock')) {
            return [new Blocker('repository', 'There is no Gemfile.lock. Coresmith installs gems in deployment mode, which needs it.', 'Commit Gemfile.lock: run bundle install, then commit the file it writes.')];
        }

        $blockers = [];

        if ($facts->gem('puma') === null) {
            $blockers[] = new Blocker('repository', "Puma isn't in Gemfile.lock. Coresmith starts Ruby apps with Puma.", 'Add gem "puma" to the Gemfile, run bundle install and commit Gemfile.lock.');
        }

        if (in_array($framework, ['sinatra', 'rack'], true) && !$facts->exists('config.ru')) {
            $blockers[] = new Blocker('framework', 'There is no config.ru, so Puma has no app to start.', 'Add a config.ru that runs the app, for example: ' . ($framework === 'sinatra' ? 'run Sinatra::Application.' : 'run Hanami.app.'));
        } elseif ($framework === 'rails' && !$facts->exists('bin/rails')) {
            $blockers[] = new Blocker('framework', "There is no bin/rails, so this isn't a Rails app {$facts->at()}.", 'Pick Sinatra if it uses Sinatra, or Rack for any other app with a config.ru.');
        }

        $platform = $arch === null ? null : $this->platformMissing($facts, $arch);

        return $platform === null ? $blockers : [...$blockers, $platform];
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        $rails = $facts->gem('railties');

        return $runtime !== null && $runtime->source === ResolvedRuntime::FROM_DEFAULT && $rails !== null
            ? "Nothing names a Ruby version, so Coresmith would pick the newest, Ruby {$runtime->version}, which gems locked with Rails {$rails} may not install or load on. Add a .ruby-version with the Ruby the app runs on, for example 3.3.6."
            : null;
    }

    public function cronExample(Application $application): string
    {
        return strtolower((string) $application->framework) === 'rails' ? 'bin/rails reports:send' : 'bundle exec rake reports:send';
    }

    public function tooling(Application $application, RepositoryFacts $facts): array
    {
        return [];
    }

    public function pathDirectories(string $appDirectory): array
    {
        return [];
    }

    public function nativeTools(): ?NativeTools
    {
        return app(NativeGemTools::class);
    }

    public function frameworkLabel(string $framework): ?string
    {
        return null;
    }

    public function explain(string $phase, string $output, Stack $stack): ?string
    {
        return null;
    }

    private function platformMissing(RepositoryFacts $facts, string $arch): ?Blocker
    {
        $local = match ($arch) {
            'x86_64', 'amd64' => 'x86_64-linux',
            'aarch64', 'arm64' => 'aarch64-linux',
            default => null,
        };

        if ($local === null || preg_match('/^PLATFORMS\r?\n((?:  \S[^\r\n]*\r?\n?)+)/m', (string) $facts->file('Gemfile.lock'), $matches) !== 1) {
            return null;
        }

        $platforms = array_values(array_filter(array_map(trim(...), preg_split('/\r\n|\n|\r/', $matches[1]) ?: []), fn(string $platform): bool => $platform !== ''));

        foreach ($platforms as $platform) {
            if ($platform === 'ruby' || $platform === $local || str_starts_with($platform, $local . '-')) {
                return null;
            }
        }

        return new Blocker(
            'repository',
            'Gemfile.lock lists only the ' . Arr::join($platforms, ', ', ' and ') . ' platform' . (count($platforms) === 1 ? '' : 's') . ", and this server is {$local}, so bundle install refuses it.",
            "Run bundle lock --add-platform {$local}, then commit Gemfile.lock.",
        );
    }

    private function gemfileNames(RepositoryFacts $facts, string $gem): bool
    {
        return preg_match('/^\s*gem\s+[\'"]' . preg_quote($gem, '/') . '[\'"]/m', (string) $facts->file('Gemfile')) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function oldBundler(RepositoryFacts $facts): array
    {
        $lock = $facts->file('Gemfile.lock');

        if ($lock === null) {
            return [];
        }

        $bundler = preg_match('/^BUNDLED WITH\R\s+(\d+\.\d+[\w.]*)/m', $lock, $matches) === 1 ? $matches[1] : null;

        return match (true) {
            $bundler === null => ['runtime_version' => "Gemfile.lock doesn't say which Bundler wrote it, so it predates Bundler 1.10. Coresmith builds Ruby 3.2 and newer, where locks that old often fail to install. Run bundle update --bundler, then commit Gemfile.lock."],
            version_compare($bundler, '2.4', '<') => ['runtime_version' => "Gemfile.lock was written by Bundler {$bundler}. Coresmith builds Ruby 3.2 and newer, where locks that old often fail to install. Run bundle update --bundler, then commit Gemfile.lock."],
            default => [],
        };
    }

    private function rackSource(RepositoryFacts $facts): string
    {
        foreach (self::RACK_FRAMEWORKS as $gem) {
            $version = $facts->gem($gem);

            if ($version !== null || $this->gemfileNames($facts, $gem)) {
                return 'config.ru, with ' . ($version === null ? "{$gem} in Gemfile" : "{$gem} {$version} in Gemfile.lock");
            }
        }

        return "config.ru {$facts->at()}";
    }
}
