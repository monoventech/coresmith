<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Enums\ApplicationType;
use App\Exceptions\RuntimeUnavailable;
use App\Models\Application;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\StackResolver;
use App\Services\Runtime\VersionConstraint;
use Closure;
use Illuminate\Support\Arr;

final class StackChecks
{
    public const SERVER_ADAPTERS = ['@sveltejs/adapter-node', 'svelte-adapter-bun'];

    public const OTHER_HOST_PRESETS = [
        'aws-amplify', 'aws-lambda', 'aws_lambda', 'azure', 'azure-functions', 'azure-swa', 'cloudflare', 'cloudflare-durable', 'cloudflare-module', 'cloudflare-pages',
        'cloudflare-pages-static', 'cloudflare_module', 'cloudflare_pages', 'deno', 'deno-deploy', 'deno-server', 'deno_deploy', 'firebase', 'github', 'github-pages',
        'github_pages', 'gitlab-pages', 'iis', 'iis-node', 'netlify', 'netlify-builder', 'netlify-edge', 'netlify-static', 'netlify_edge', 'service-worker', 'static',
        'vercel', 'vercel-edge', 'vercel-static', 'vercel_edge', 'winterjs',
    ];

    private const PHP_INDEXES = ['public' => 'public/index.php', 'web' => 'web/index.php', '' => 'index.php'];

    private const STATIC_INDEXES = ['' => 'index.html', 'public' => 'public/index.html'];

    public function __construct(private readonly StackResolver $resolver, private readonly Catalog $catalog) {}

    /**
     * @param  Closure(string): list<string>  $installed
     * @param  (Closure(string): ?list<string>)|null  $extensions
     * @return list<Blocker>
     */
    public function blockers(Application $choice, RepositoryFacts $facts, Closure $installed, bool $runtime = true, ?Closure $extensions = null, ?string $arch = null): array
    {
        if (!$facts->complete || trim((string) $choice->custom_blueprint) !== '') {
            return [];
        }

        $framework = strtolower((string) $choice->framework);

        $blockers = match ($choice->type) {
            ApplicationType::Php => $this->php($choice, $framework, $facts),
            ApplicationType::Ruby => $this->ruby($framework, $facts, $arch),
            ApplicationType::Js => $this->javascript($choice, $framework, $facts),
            ApplicationType::Static => $this->static($choice, $facts),
            default => [],
        };

        $mismatch = $this->managerMismatch($facts);
        $blockers = $mismatch === null ? $blockers : [...$blockers, $mismatch];

        return $runtime ? [...$blockers, ...$this->runtime($choice, $facts, $installed, $extensions)] : $blockers;
    }

    public function svelteAdapter(RepositoryFacts $facts): ?string
    {
        foreach (['svelte.config.js', 'svelte.config.ts'] as $file) {
            if (preg_match('~(?:from|require\()\s*[\'"](@sveltejs/adapter-[a-z0-9-]+|svelte-adapter-bun)[\'"]~', (string) $facts->file($file), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    public function nextExport(RepositoryFacts $facts): bool
    {
        return $this->config($facts, ['next.config.js', 'next.config.mjs', 'next.config.ts'], '~output\s*:\s*[\'"]export[\'"]~');
    }

    public function nuxtGenerate(RepositoryFacts $facts): bool
    {
        return str_contains((string) ($facts->json('package.json')['scripts']['build'] ?? ''), 'nuxt generate');
    }

    /**
     * @return array{string, bool}|null
     */
    public function nitroPreset(RepositoryFacts $facts): ?array
    {
        $build = (string) ($facts->json('package.json')['scripts']['build'] ?? '');

        if (preg_match('/(?:NITRO_PRESET|SERVER_PRESET)=([\w-]+)|--preset[ =]([\w-]+)/', $build, $matches) === 1) {
            $preset = strtolower($matches[1] !== '' ? $matches[1] : $matches[2]);

            return in_array($preset, self::OTHER_HOST_PRESETS, true) ? [$preset, true] : null;
        }

        foreach (['nuxt.config.ts', 'nuxt.config.js'] as $file) {
            $block = $this->nitroBlock((string) $facts->file($file));

            if ($block === null || preg_match('/\bpreset\s*:\s*([^,\n}]+)/', $block, $matches) !== 1) {
                continue;
            }

            $expression = trim($matches[1]);

            if (preg_match('/^([\'"`])([\w-]+)\1$/', $expression, $literal) === 1) {
                return in_array(strtolower($literal[2]), self::OTHER_HOST_PRESETS, true) ? [strtolower($literal[2]), true] : null;
            }

            preg_match_all('/[\'"`]([\w-]+)[\'"`]/', $expression, $literals);

            foreach ($literals[1] as $value) {
                if (in_array(strtolower($value), self::OTHER_HOST_PRESETS, true)) {
                    return [strtolower($value), false];
                }
            }
        }

        return null;
    }

    public function astroServer(RepositoryFacts $facts): bool
    {
        return $facts->dependency('@astrojs/node') !== null && $this->config($facts, ['astro.config.mjs', 'astro.config.ts', 'astro.config.js'], '~@astrojs/node~');
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        return match (true) {
            $facts->dependency('@sveltejs/kit') !== null => $this->svelteAdapter($facts) === '@sveltejs/adapter-static' && !$this->config($facts, ['svelte.config.js', 'svelte.config.ts'], '~pages\s*:~') ? '/build' : null,
            $facts->dependency('next') !== null => $this->nextExport($facts) && !$this->config($facts, ['next.config.js', 'next.config.mjs', 'next.config.ts'], '~distDir\s*:~') ? '/out' : null,
            $facts->dependency('nuxt') !== null => $this->nuxtGenerate($facts) ? '/.output/public' : null,
            $facts->dependency('astro') !== null => !$this->astroServer($facts) && !$this->config($facts, ['astro.config.mjs', 'astro.config.ts', 'astro.config.js'], '~outDir\s*:~') ? '/dist' : null,
            $facts->dependency('vite') !== null => $this->config($facts, ['vite.config.ts', 'vite.config.js'], '~outDir\s*:~') ? null : '/dist',
            default => null,
        };
    }

    /**
     * @param  list<string>  $files
     */
    public function config(RepositoryFacts $facts, array $files, string $pattern): bool
    {
        foreach ($files as $file) {
            if (preg_match($pattern, (string) $facts->file($file)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Blocker>
     */
    private function php(Application $choice, string $framework, RepositoryFacts $facts): array
    {
        $blockers = [];
        $name = $framework === 'symfony' ? 'Symfony' : 'Laravel';

        if (in_array($framework, ['laravel', 'symfony'], true) && !$facts->has('composer.json')) {
            $blockers[] = new Blocker('framework', "There is no composer.json, so this isn't a {$name} app at the repository's root.", 'Pick Plain PHP.');
        } elseif ($framework === 'laravel' && !$facts->exists('artisan')) {
            $blockers[] = new Blocker('framework', "There is no artisan file at the repository's root, so this isn't a Laravel app.", 'Pick Plain PHP, or Symfony if it uses Symfony.');
        }

        $platform = $this->platformBehindLock($facts);

        if ($platform !== null) {
            $blockers[] = $platform;
        }

        $web = trim((string) $choice->web_directory, '/');
        $index = self::PHP_INDEXES[$web] ?? null;

        if ($index !== null && !$facts->exists($index)) {
            $found = array_search(true, array_map(fn(string $path): bool => $facts->exists($path), self::PHP_INDEXES), true);
            $blockers[] = new Blocker(
                'web_directory',
                "There is no {$index}, so the web root /{$web} would serve nothing.",
                is_string($found) ? 'Set the web root to /' . $found . ', where index.php is.' : 'Set the web root to the folder that holds index.php.',
            );
        }

        return $blockers;
    }

    private function platformBehindLock(RepositoryFacts $facts): ?Blocker
    {
        $platform = $facts->json('composer.json')['config']['platform']['php'] ?? null;
        $packages = $facts->json(RepositoryProbe::COMPOSER_LOCK)['packages'] ?? null;

        if (!is_string($platform) || preg_match('/^\d+(?:\.\d+){0,2}$/', $platform) !== 1 || !is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            $needs = is_array($package) && is_array($package['require'] ?? null) ? ($package['require']['php'] ?? null) : null;
            $constraint = is_string($needs) ? VersionConstraint::parse($needs, 'composer') : null;

            if ($constraint !== null && !$constraint->satisfiedBy($platform)) {
                $locked = trim((string) ($package['name'] ?? '') . ' ' . ltrim((string) ($package['version'] ?? ''), 'v'));

                return new Blocker(
                    'repository',
                    "composer.json sets config.platform.php to {$platform}, but composer.lock holds {$locked}, which needs PHP {$needs}, so composer install fails on any server.",
                    'Raise config.platform.php in composer.json to a PHP composer.lock allows, or remove it, then run composer update --lock and commit both files.',
                );
            }
        }

        return null;
    }

    /**
     * @return list<Blocker>
     */
    private function ruby(string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        if (!$facts->has('Gemfile')) {
            return [new Blocker('type', "There is no Gemfile at the repository's root, so this isn't a Ruby app.", 'Pick the language the repository uses.')];
        }

        if (!$facts->has('Gemfile.lock')) {
            return [new Blocker('repository', 'There is no Gemfile.lock. Coresmith installs gems in deployment mode, which needs it.', 'Commit Gemfile.lock: run bundle install, then commit the file it writes.')];
        }

        $blockers = [];

        if ($facts->gem('puma') === null) {
            $blockers[] = new Blocker('repository', "Puma isn't in Gemfile.lock. Coresmith starts Ruby apps with Puma.", 'Add gem "puma" to the Gemfile, run bundle install and commit Gemfile.lock.');
        }

        if ($framework === 'sinatra' && !$facts->exists('config.ru')) {
            $blockers[] = new Blocker('framework', 'There is no config.ru, so Puma has no app to start.', 'Add a config.ru that runs the app, for example: run Sinatra::Application.');
        } elseif ($framework === 'rails' && !$facts->exists('bin/rails')) {
            $blockers[] = new Blocker('framework', "There is no bin/rails, so this isn't a Rails app at the repository's root.", 'Pick Sinatra if it uses Sinatra.');
        }

        $platform = $arch === null ? null : $this->platformMissing($facts, $arch);

        return $platform === null ? $blockers : [...$blockers, $platform];
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

    /**
     * @return list<Blocker>
     */
    private function javascript(Application $choice, string $framework, RepositoryFacts $facts): array
    {
        $runtime = $choice->js_runtime ?: 'bun';

        if ($runtime === 'deno') {
            if (!$facts->has('deno.json') && !$facts->has('deno.jsonc') && !$facts->has('package.json')) {
                return [new Blocker('js_runtime', 'There is no deno.json or package.json, so there is nothing for Deno to run.', 'Pick the runtime the repository uses.')];
            }

            return $facts->denoTask('start') || $facts->exists('main.ts')
                ? []
                : [new Blocker('repository', 'There is no start task in deno.json, and no main.ts to run instead.', 'Add a start task to deno.json, for example "start": "deno run --allow-net main.ts".')];
        }

        if (!$facts->has('package.json')) {
            return [new Blocker(
                'js_runtime',
                'There is no package.json, so there is nothing for ' . ($runtime === 'node' ? 'Node' : 'Bun') . ' to install or start.',
                $facts->has('deno.json') || $facts->has('deno.jsonc') ? 'Pick Deno, which runs deno.json.' : 'Pick the language the repository uses.',
            )];
        }

        $blockers = match ($framework) {
            'sveltekit' => $this->svelteKit($facts),
            'nextjs', 'next' => $this->next($facts),
            'nuxt' => $this->nuxt($facts),
            'astro' => $this->astro($facts),
            default => $this->started($facts),
        };
        $port = $this->fixedPort($facts);
        $blockers = $port === null ? $blockers : [...$blockers, $port];

        return $facts->dependency('fibers') === null
            ? $blockers
            : [...$blockers, new Blocker('repository', 'package.json depends on fibers, which does not build on Node.js 16 or later, and Coresmith installs Node.js ' . $this->catalog->range('nodejs') . '.', 'Remove fibers from package.json. Its author retired it when Node.js 16 came out.')];
    }

    private function fixedPort(RepositoryFacts $facts): ?Blocker
    {
        $script = JsTooling::startScriptFor($facts);
        $command = $script === null ? null : ($facts->json('package.json')['scripts'][$script] ?? null);

        if (!is_string($command) || preg_match('/(?:^|[\s;&|(])(?:(?:-p|--port)(?:\s+|=)|PORT=)(\d{2,5})(?=$|[\s;&|)])/', $command, $matches) !== 1) {
            return null;
        }

        return new Blocker('repository', "The {$script} script sets port {$matches[1]}, but Coresmith gives each app its own port in PORT, so visitors would get a 502.", "Take the port out of the {$script} script so the app listens on PORT, which Coresmith sets.");
    }

    /**
     * @return list<Blocker>
     */
    private function svelteKit(RepositoryFacts $facts): array
    {
        if ($facts->dependency('@sveltejs/kit') === null) {
            return [new Blocker('framework', "@sveltejs/kit isn't in package.json, so this isn't a SvelteKit app.", 'Pick the framework the app uses, or API or other.')];
        }

        $adapter = $this->svelteAdapter($facts);

        return match (true) {
            $adapter === null, in_array($adapter, self::SERVER_ADAPTERS, true) => [],
            $adapter === '@sveltejs/adapter-static' => [new Blocker('type', 'svelte.config.js uses @sveltejs/adapter-static, so the build is static files, not a server.', 'Pick Static site with the web root /build.')],
            default => [new Blocker('framework', "svelte.config.js uses {$adapter}, which builds the app for that hosting platform's own servers.", 'Install @sveltejs/adapter-node, use it in svelte.config.js, then commit both.')],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function next(RepositoryFacts $facts): array
    {
        return match (true) {
            $facts->dependency('next') === null => [new Blocker('framework', "next isn't in package.json, so this isn't a Next.js app.", 'Pick the framework the app uses, or API or other.')],
            $this->nextExport($facts) => [new Blocker('type', "next.config sets output: 'export', so the build is static files, not a server.", 'Pick Static site with the web root /out.')],
            !$facts->script('start') => [new Blocker('repository', 'There is no start script in package.json, so there is nothing to start the Next.js server with.', 'Add "start": "next start" to the scripts in package.json.')],
            default => [],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function nuxt(RepositoryFacts $facts): array
    {
        $preset = $this->nitroPreset($facts);

        return match (true) {
            $facts->dependency('nuxt') === null => [new Blocker('framework', "nuxt isn't in package.json, so this isn't a Nuxt app.", 'Pick the framework the app uses, or API or other.')],
            $this->nuxtGenerate($facts) => [new Blocker('type', 'The build script runs nuxt generate, so the build is static files, not a server.', 'Pick Static site with the web root /.output/public.')],
            !$facts->script('build') => [new Blocker('repository', 'There is no build script in package.json, so the Nuxt server is never built.', 'Add "build": "nuxt build" to the scripts in package.json.')],
            $preset !== null && $preset[1] => [new Blocker('framework', "Nitro's preset is {$preset[0]}, so nuxt build makes the app for that host, not the Node server Coresmith runs.", 'Remove the preset or set it to node-server. If the app is static, pick Static site with the web root /.output/public.')],
            default => [],
        };
    }

    private function managerMismatch(RepositoryFacts $facts): ?Blocker
    {
        $declared = JsTooling::declaredManager($facts);
        $lockfile = JsTooling::lockfileFor($facts);
        $locked = match ($lockfile) {
            'pnpm-lock.yaml' => 'pnpm',
            'yarn.lock' => 'yarn',
            default => null,
        };

        if ($declared === null || $locked === null || $declared === $locked) {
            return null;
        }

        return new Blocker(
            'repository',
            "package.json names {$declared} in packageManager, but the lockfile is {$lockfile}, and corepack refuses to install with a different package manager.",
            'Commit a ' . JsTooling::LOCKFILES[$declared][0] . " made with {$declared}, or change packageManager to {$locked}.",
        );
    }

    private function nitroBlock(string $config): ?string
    {
        if (preg_match('/\bnitro\s*:\s*\{/', $config, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;

        for ($at = $start, $length = strlen($config); $at < $length; $at++) {
            $depth += match ($config[$at]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return substr($config, $start, $at - $start);
            }
        }

        return substr($config, $start);
    }

    /**
     * @return list<Blocker>
     */
    private function astro(RepositoryFacts $facts): array
    {
        return match (true) {
            $facts->dependency('astro') === null => [new Blocker('framework', "astro isn't in package.json, so this isn't an Astro app.", 'Pick the framework the app uses, or API or other.')],
            !$this->astroServer($facts) => [new Blocker('type', 'This Astro app has no @astrojs/node adapter, so the build is static files, not a server.', 'Pick Static site with the web root /dist, or add @astrojs/node to serve pages on demand.')],
            default => [],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function started(RepositoryFacts $facts): array
    {
        return JsTooling::startScriptFor($facts) !== null || JsTooling::safeMain($facts->json('package.json')['main'] ?? null) !== null || $facts->exists('server.js')
            ? []
            : [new Blocker('repository', 'There is no start script and no main file in package.json, so Coresmith has nothing to start.', 'Add a start script to package.json, for example "start": "node server.js".')];
    }

    /**
     * @return list<Blocker>
     */
    private function static(Application $choice, RepositoryFacts $facts): array
    {
        $web = trim((string) $choice->web_directory, '/');

        if ($facts->script('build')) {
            $output = $this->staticOutput($facts);

            return $output !== null && $output !== '/' . $web
                ? [new Blocker('web_directory', "The build writes to {$output}, so the web root /{$web} would be empty.", "Set the web root to {$output}.")]
                : [];
        }

        $index = self::STATIC_INDEXES[$web] ?? null;

        if ($index === null || $facts->exists($index)) {
            return [];
        }

        $found = array_search(true, array_map(fn(string $path): bool => $facts->exists($path), self::STATIC_INDEXES), true);

        return [new Blocker(
            'web_directory',
            "There is no {$index}, so the web root /{$web} would serve nothing.",
            is_string($found) ? 'Set the web root to /' . $found . ', where index.html is.' : 'Set the web root to the folder that holds index.html.',
        )];
    }

    /**
     * @param  Closure(string): list<string>  $installed
     * @param  (Closure(string): ?list<string>)|null  $extensions
     * @return list<Blocker>
     */
    private function runtime(Application $choice, RepositoryFacts $facts, Closure $installed, ?Closure $extensions): array
    {
        if ($choice->type === null) {
            return [];
        }

        try {
            $stack = $this->resolver->resolve($choice, $facts, $installed);
        } catch (RuntimeUnavailable $refusal) {
            return [new Blocker('runtime_version', $refusal->getMessage())];
        }

        $php = $stack->versions()['php'] ?? null;
        $modules = $php === null || $extensions === null ? null : $extensions($php);
        $missing = $modules === null ? null : $this->missingExtension($facts, $modules);

        return $missing === null ? [] : [new Blocker('repository', "{$missing[0]} needs the {$missing[1]} PHP extension, which PHP {$php} on this server doesn't have, so composer install fails.", $missing[2])];
    }

    /**
     * @param  list<string>  $modules
     * @return array{string, string, string}|null
     */
    private function missingExtension(RepositoryFacts $facts, array $modules): ?array
    {
        $have = array_map(self::extensionName(...), $modules);
        $have = in_array('zend_opcache', $have, true) ? [...$have, 'opcache'] : $have;
        $packages = $facts->json(RepositoryProbe::COMPOSER_LOCK)['packages'] ?? [];
        $packages = is_array($packages) ? array_values(array_filter($packages, is_array(...))) : [];
        $provided = [];
        $needers = [['composer.json', (array) ($facts->json('composer.json')['require'] ?? []), null]];

        foreach ($packages as $package) {
            $name = (string) ($package['name'] ?? '');
            $needers[] = [trim($name . ' ' . ltrim((string) ($package['version'] ?? ''), 'v')) . ' in composer.lock', (array) ($package['require'] ?? []), $name];

            foreach (array_keys((array) ($package['provide'] ?? [])) as $provide) {
                if (str_starts_with(strtolower((string) $provide), 'ext-')) {
                    $provided[] = self::extensionName(substr((string) $provide, 4));
                }
            }
        }

        foreach ($needers as [$needer, $require, $package]) {
            foreach (array_keys($require) as $requirement) {
                $requirement = (string) $requirement;

                if (!str_starts_with(strtolower($requirement), 'ext-')) {
                    continue;
                }

                $extension = strtolower(substr($requirement, 4));

                if (!in_array(self::extensionName($extension), [...$have, ...$provided], true)) {
                    return [$needer, $extension, $package === null
                        ? "Remove {$requirement} from composer.json if the app runs without it."
                        : "Remove {$package} with composer remove if the app runs without it, then commit composer.lock."];
                }
            }
        }

        return null;
    }

    private static function extensionName(string $name): string
    {
        return str_replace('-', '_', strtolower(trim($name)));
    }
}
