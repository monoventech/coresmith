<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Packages;

use App\Enums\PackageSource;
use App\Models\PackageCredential;
use App\Models\Team;
use App\Services\Runtime\RepositoryFacts;

final class PrivateSources
{
    public const PUBLIC_HOSTS = [
        'packagist.org', 'repo.packagist.org', 'registry.npmjs.org', 'registry.yarnpkg.com', 'registry.npmmirror.com',
        'pypi.org', 'pypi.python.org', 'files.pythonhosted.org', 'download.pytorch.org',
        'repo.maven.apache.org', 'repo1.maven.org', 'central.sonatype.com', 'oss.sonatype.org', 's01.oss.sonatype.org',
        'jitpack.io', 'plugins.gradle.org', 'repo.spring.io', 'maven.google.com', 'dl.google.com', 'rubygems.org',
    ];

    private const GIT_FORGES = ['github.com', 'gitlab.com', 'bitbucket.org'];

    private const URL = '(https?://[^\s"\'<>]+)';

    /**
     * @return list<array{source: PackageSource, host: string, id: ?string}>
     */
    public function in(RepositoryFacts $facts): array
    {
        $found = [];

        foreach ($facts->files as $path => $content) {
            $name = basename($path);

            $sources = match (true) {
                $name === 'composer.json' => $this->composer($content),
                $name === '.npmrc' => $this->each(PackageSource::Npm, '~^\s*(?:@[a-z0-9._-]+:)?registry\s*=\s*' . self::URL . '|^\s*\/\/([^\/\s:]+)\S*:_auth(?:Token)?\s*=~mi', $content),
                $name === '.yarnrc.yml' => $this->each(PackageSource::Npm, '~npmRegistryServer:\s*["\']?' . self::URL . '|^\s+["\']?(?:https?:)?\/\/([^\/"\'\s:]+)[^:]*["\']?:\s*$~m', $content),
                $name === 'bunfig.toml' => $this->each(PackageSource::Npm, '~\b(?:url|registry)\s*=\s*["\']' . self::URL . '~', $content),
                $name === 'pyproject.toml' || $name === 'Pipfile' => $this->each(PackageSource::Python, '~^\s*url\s*=\s*["\']' . self::URL . '~m', $content),
                (bool) preg_match('/^requirements.*\.txt\z/', $name) => $this->each(PackageSource::Python, '~^\s*(?:--index-url|--extra-index-url|-i)[\s=]+' . self::URL . '~m', $content),
                $name === 'pom.xml' => $this->maven($content),
                (bool) preg_match('/^(?:build|settings)\.gradle(?:\.kts)?\z/', $name) => str_contains($content, 'credentials') ? $this->each(PackageSource::Maven, '~\burl\s*(?:=\s*)?(?:uri\s*\(\s*)?["\']' . self::URL . '~', $content) : [],
                $name === 'Gemfile' => $this->each(PackageSource::Ruby, '~^\s*source\s*\(?\s*["\']' . self::URL . '~m', $content),
                default => [],
            };

            foreach ($sources as $source) {
                $found[$source['source']->value . '|' . $source['host'] . '|' . $source['id']] ??= $source;
            }
        }

        return array_values($found);
    }

    /**
     * @return list<array{host: string, source: string}>
     */
    public function missing(RepositoryFacts $facts, Team $team): array
    {
        $credentials = $team->packageCredentials()->get()->all();
        $missing = [];

        foreach ($this->in($facts) as $source) {
            if (!$this->covered($source, $credentials)) {
                $missing[] = ['host' => $source['host'], 'source' => $source['source']->label()];
            }
        }

        return $missing;
    }

    /**
     * @param  array{source: PackageSource, host: string, id: ?string}  $source
     * @param  list<PackageCredential>  $credentials
     */
    private function covered(array $source, array $credentials): bool
    {
        foreach ($credentials as $credential) {
            $covers = match ($source['source']) {
                PackageSource::Maven => $credential->source === PackageSource::Maven && ($source['id'] === null || $credential->target === $source['id']),
                PackageSource::Composer, PackageSource::Ruby => in_array($credential->source, [$source['source'], PackageSource::Git], true) && PackageCredentials::label($credential) === $source['host'],
                default => $credential->source === $source['source'] && PackageCredentials::label($credential) === $source['host'],
            };

            if ($covers) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{source: PackageSource, host: string, id: ?string}>
     */
    private function composer(string $content): array
    {
        $manifest = json_decode($content, true);
        $repositories = is_array($manifest) && is_array($manifest['repositories'] ?? null) ? $manifest['repositories'] : [];
        $found = [];

        foreach ($repositories as $repository) {
            if (!is_array($repository) || !is_string($repository['url'] ?? null) || !in_array($repository['type'] ?? null, ['composer', 'vcs', 'git'], true)) {
                continue;
            }

            $host = $this->host($repository['url']);

            if ($host !== null && ($repository['type'] === 'composer' || !in_array($host, self::GIT_FORGES, true))) {
                $found[] = ['source' => PackageSource::Composer, 'host' => $host, 'id' => null];
            }
        }

        return $found;
    }

    /**
     * @return list<array{source: PackageSource, host: string, id: ?string}>
     */
    private function maven(string $content): array
    {
        $content = (string) preg_replace('/<distributionManagement>.*?<\/distributionManagement>/s', '', $content);
        preg_match_all('/<(pluginRepository|repository)>(.*?)<\/\1>/s', $content, $blocks, PREG_SET_ORDER);
        $found = [];

        foreach ($blocks as $block) {
            $id = preg_match('/<id>\s*([^<\s]+)\s*<\/id>/', $block[2], $idMatch) === 1 ? $idMatch[1] : null;
            $host = preg_match('/<url>\s*([^<\s]+)\s*<\/url>/', $block[2], $urlMatch) === 1 ? $this->host($urlMatch[1]) : null;

            if ($host !== null) {
                $found[] = ['source' => PackageSource::Maven, 'host' => $host, 'id' => $id];
            }
        }

        return $found;
    }

    /**
     * @return list<array{source: PackageSource, host: string, id: ?string}>
     */
    private function each(PackageSource $source, string $pattern, string $content): array
    {
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        $found = [];

        foreach ($matches as $match) {
            $host = ($match[1] ?? '') !== '' ? $this->host($match[1]) : $this->host('https://' . ($match[2] ?? ''));

            if ($host !== null) {
                $found[] = ['source' => $source, 'host' => $host, 'id' => null];
            }
        }

        return $found;
    }

    private function host(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' || in_array($host, self::PUBLIC_HOSTS, true) ? null : $host;
    }
}
