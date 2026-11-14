<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final readonly class JsFrameworks
{
    public const SERVER_ADAPTERS = ['@sveltejs/adapter-node', 'svelte-adapter-bun'];

    public const OTHER_HOST_PRESETS = [
        'aws-amplify', 'aws-lambda', 'aws_lambda', 'azure', 'azure-functions', 'azure-swa', 'cloudflare', 'cloudflare-durable', 'cloudflare-module', 'cloudflare-pages',
        'cloudflare-pages-static', 'cloudflare_module', 'cloudflare_pages', 'deno', 'deno-deploy', 'deno-server', 'deno_deploy', 'firebase', 'github', 'github-pages',
        'github_pages', 'gitlab-pages', 'iis', 'iis-node', 'netlify', 'netlify-builder', 'netlify-edge', 'netlify-static', 'netlify_edge', 'service-worker', 'static',
        'vercel', 'vercel-edge', 'vercel-static', 'vercel_edge', 'winterjs',
    ];

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
}
