<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Packages;

use App\Enums\PackageSource;
use App\Models\PackageCredential;

final class StepSecretsBuilder
{
    public const NPMRC = '.npmrc';

    public const YARNRC = '.yarnrc.yml';

    public const NETRC = '.netrc';

    public const MAVEN_SETTINGS = '.m2/settings.xml';

    public const GRADLE_PROPERTIES = '.gradle/gradle.properties';

    public const PROJECT_FILES = [self::NPMRC, self::YARNRC];

    public const YARN_DEFAULT_REGISTRY = 'https://registry.yarnpkg.com';

    private const VARIABLE = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * @param  iterable<PackageCredential>  $credentials
     * @param  array<string, string>  $environment
     * @param  array<string, string>  $projectFiles
     */
    public function build(iterable $credentials, array $environment = [], array $projectFiles = []): StepSecrets
    {
        $by = array_fill_keys(array_column(PackageSource::cases(), 'value'), []);

        foreach ($credentials as $credential) {
            $by[$credential->source->value][] = $credential;
        }

        $variables = [];
        $files = [];
        $secrets = [];

        foreach ($by as $group) {
            foreach ($group as $credential) {
                array_push($secrets, ...self::forms((string) $credential->secret));
            }
        }

        $composer = $this->composer($by[PackageSource::Composer->value], $by[PackageSource::Git->value], $environment);

        if ($composer !== null) {
            $variables['COMPOSER_AUTH'] = $composer;
        }

        if ($by[PackageSource::Npm->value] !== []) {
            $files[self::NPMRC] = $this->npmrc($by[PackageSource::Npm->value]);
            $files[self::YARNRC] = $this->yarnrc($by[PackageSource::Npm->value]);
            $variables = [...$variables, ...$this->projectTokens($by[PackageSource::Npm->value], $environment, $projectFiles)];
        }

        $netrc = $this->netrc([...$by[PackageSource::Python->value], ...$by[PackageSource::Git->value]]);

        if ($netrc !== '') {
            $files[self::NETRC] = $netrc;
        }

        if ($by[PackageSource::Maven->value] !== []) {
            $files[self::MAVEN_SETTINGS] = $this->mavenSettings($by[PackageSource::Maven->value]);
            $files[self::GRADLE_PROPERTIES] = $this->gradleProperties($by[PackageSource::Maven->value]);
        }

        if ($by[PackageSource::Git->value] !== []) {
            $variables['GOPRIVATE'] = implode(',', array_values(array_unique(array_map(fn(PackageCredential $credential): string => strtolower((string) $credential->target), $by[PackageSource::Git->value]))));
        }

        foreach ([...$by[PackageSource::Git->value], ...$by[PackageSource::Ruby->value]] as $credential) {
            $variables[self::bundlerKey(self::host((string) $credential->target))] = rawurlencode($this->username($credential)) . ':' . rawurlencode((string) $credential->secret);
        }

        return new StepSecrets($variables, $files, array_values(array_unique(array_filter($secrets, fn(string $secret): bool => $secret !== ''))));
    }

    /**
     * @return list<string>
     */
    public static function forms(string $secret): array
    {
        return array_values(array_unique([$secret, rawurlencode($secret), substr((string) json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1)]));
    }

    public static function host(string $target): string
    {
        return strtolower(explode('/', $target, 2)[0]);
    }

    public static function registry(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = '/' . trim((string) ($parts['path'] ?? ''), '/');

        return 'https://' . $host . $port . rtrim($path, '/') . '/';
    }

    /**
     * @return list<string>
     */
    public static function scopes(?string $scopes): array
    {
        return array_values(array_unique(array_map(
            fn(string $scope): string => '@' . ltrim(strtolower($scope), '@'),
            preg_split('/[\s,]+/', trim((string) $scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )));
    }

    public static function bundlerKey(string $host): string
    {
        return 'BUNDLE_' . strtoupper(str_replace(['-', '.'], ['___', '__'], $host));
    }

    /**
     * @param  list<PackageCredential>  $composer
     * @param  list<PackageCredential>  $git
     * @param  array<string, string>  $environment
     */
    private function composer(array $composer, array $git, array $environment): ?string
    {
        if ($composer === [] && $git === []) {
            return null;
        }

        $auth = [];

        foreach ($git as $credential) {
            $host = self::host((string) $credential->target);

            match (true) {
                $host === 'github.com' => $auth['github-oauth'][$host] = (string) $credential->secret,
                $host === 'gitlab.com' => $auth['gitlab-token'][$host] = (string) $credential->secret,
                default => $auth['http-basic'][$host] = ['username' => $this->username($credential), 'password' => (string) $credential->secret],
            };
        }

        foreach ($composer as $credential) {
            $host = self::host((string) $credential->target);

            if (filled($credential->username)) {
                $auth['http-basic'][$host] = ['username' => (string) $credential->username, 'password' => (string) $credential->secret];
            } else {
                $auth['bearer'][$host] = (string) $credential->secret;
            }
        }

        $own = json_decode($environment['COMPOSER_AUTH'] ?? '', true);

        if (is_array($own)) {
            foreach ($own as $type => $hosts) {
                if (is_string($type) && is_array($hosts)) {
                    foreach ($hosts as $host => $value) {
                        $auth[$type][$host] = $value;
                    }
                }
            }
        }

        return (string) json_encode($auth, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<PackageCredential>  $credentials
     */
    private function npmrc(array $credentials): string
    {
        $scopes = [];
        $tokens = [];

        foreach ($credentials as $credential) {
            $registry = self::registry((string) $credential->target);

            foreach (self::scopes($credential->scope) as $scope) {
                $scopes[] = $scope . ':registry=' . $registry;
            }

            $tokens[] = substr($registry, strlen('https:')) . ':_authToken=' . $credential->secret;
        }

        return implode("\n", [...$scopes, ...$tokens]) . "\n";
    }

    /**
     * @param  list<PackageCredential>  $credentials
     */
    private function yarnrc(array $credentials): string
    {
        $registries = ['npmRegistries:'];
        $scopes = [];

        foreach ($credentials as $credential) {
            $registry = rtrim(self::registry((string) $credential->target), '/');

            $registries[] = '  ' . $this->yaml(substr($registry, strlen('https:'))) . ':';
            $registries[] = '    npmAlwaysAuth: true';
            $registries[] = '    npmAuthToken: ' . $this->yaml((string) $credential->secret);

            foreach (self::scopes($credential->scope) as $scope) {
                $scopes[] = '  ' . $this->yaml(ltrim($scope, '@')) . ':';
                $scopes[] = '    npmRegistryServer: ' . $this->yaml($registry);
            }
        }

        return implode("\n", [...$registries, ...($scopes === [] ? [] : ['npmScopes:', ...$scopes])]) . "\n";
    }

    /**
     * @param  list<PackageCredential>  $credentials
     * @param  array<string, string>  $environment
     * @param  array<string, string>  $projectFiles
     * @return array<string, string>
     */
    private function projectTokens(array $credentials, array $environment, array $projectFiles): array
    {
        $wanted = [];

        foreach ([...$this->npmrcVariables($projectFiles[self::NPMRC] ?? ''), ...$this->yarnrcVariables($projectFiles[self::YARNRC] ?? '')] as [$registry, $variable]) {
            $token = $this->tokenFor($credentials, $registry);

            if ($token !== null) {
                $wanted[$variable][$token] = true;
            }
        }

        $variables = [];

        foreach ($wanted as $variable => $tokens) {
            if (count($tokens) === 1 && !array_key_exists($variable, $environment)) {
                $variables[$variable] = (string) array_key_first($tokens);
            }
        }

        return $variables;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function npmrcVariables(string $npmrc): array
    {
        preg_match_all('/^[ \t]*(\/\/[^\s=]+?):_authToken[ \t]*=[ \t]*\$\{(' . self::VARIABLE . ')\}[ \t]*$/m', $npmrc, $matches, PREG_SET_ORDER);

        return array_map(fn(array $match): array => ['https:' . $match[1], $match[2]], $matches);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function yarnrcVariables(string $yarnrc): array
    {
        $stack = [];
        $default = null;
        $scopeRegistries = [];
        $tokens = [];

        foreach (preg_split('/\r\n|\n|\r/', $yarnrc) ?: [] as $line) {
            if (preg_match('/^( *)(?:"([^"]*)"|\'([^\']*)\'|([^\s#"\'][^:#]*?)) *:(?: +(.*?))? *$/', $line, $match) !== 1) {
                continue;
            }

            $indent = strlen($match[1]);
            $key = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : $match[4]);
            $value = trim($match[5] ?? '', " \"'");

            while ($stack !== [] && end($stack)[0] >= $indent) {
                array_pop($stack);
            }

            $path = [...array_column($stack, 1), $key];

            if ($value === '') {
                $stack[] = [$indent, $key];

                continue;
            }

            if ($key === 'npmRegistryServer' && count($path) === 1) {
                $default = $value;
            } elseif ($key === 'npmRegistryServer' && count($path) === 3 && $path[0] === 'npmScopes') {
                $scopeRegistries[$path[1]] = $value;
            } elseif ($key === 'npmAuthToken' && preg_match('/^\$\{(' . self::VARIABLE . ')(?::?-[^}]*)?\}$/', $value, $variable) === 1) {
                $tokens[] = [$path, $variable[1]];
            }
        }

        $found = [];

        foreach ($tokens as [$path, $variable]) {
            $registry = match (true) {
                count($path) === 1 => $default ?? self::YARN_DEFAULT_REGISTRY,
                count($path) === 3 && $path[0] === 'npmRegistries' => $path[1],
                count($path) === 3 && $path[0] === 'npmScopes' => $scopeRegistries[$path[1]] ?? $default ?? self::YARN_DEFAULT_REGISTRY,
                default => null,
            };

            if ($registry !== null) {
                $found[] = [str_starts_with($registry, '//') ? 'https:' . $registry : $registry, $variable];
            }
        }

        return $found;
    }

    /**
     * @param  list<PackageCredential>  $credentials
     */
    private function tokenFor(array $credentials, string $registry): ?string
    {
        $wanted = self::registry($registry);
        $host = parse_url($wanted, PHP_URL_HOST);
        $best = null;
        $length = -1;

        foreach ($credentials as $credential) {
            $known = self::registry((string) $credential->target);

            if (parse_url($known, PHP_URL_HOST) !== $host || (!str_starts_with($wanted, $known) && !str_starts_with($known, $wanted))) {
                continue;
            }

            if (strlen($known) > $length) {
                $best = (string) $credential->secret;
                $length = strlen($known);
            }
        }

        return $best;
    }

    /**
     * @param  list<PackageCredential>  $credentials
     */
    private function netrc(array $credentials): string
    {
        $machines = [];

        foreach ($credentials as $credential) {
            $host = self::host((string) $credential->target);
            $machines[$host] ??= "machine {$host} login {$this->username($credential)} password {$credential->secret}";
        }

        return $machines === [] ? '' : implode("\n", $machines) . "\n";
    }

    /**
     * @param  list<PackageCredential>  $credentials
     */
    private function mavenSettings(array $credentials): string
    {
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<settings>', '  <servers>'];

        foreach ($credentials as $credential) {
            $lines[] = '    <server>';
            $lines[] = '      <id>' . $this->xml((string) $credential->target) . '</id>';
            $lines[] = '      <username>' . $this->xml((string) $credential->username) . '</username>';
            $lines[] = '      <password>' . $this->xml((string) $credential->secret) . '</password>';
            $lines[] = '    </server>';
        }

        return implode("\n", [...$lines, '  </servers>', '</settings>']) . "\n";
    }

    /**
     * @param  list<PackageCredential>  $credentials
     */
    private function gradleProperties(array $credentials): string
    {
        $lines = [];

        foreach ($credentials as $credential) {
            $lines[] = $this->property($credential->target . 'Username', true) . '=' . $this->property((string) $credential->username, false);
            $lines[] = $this->property($credential->target . 'Password', true) . '=' . $this->property((string) $credential->secret, false);
        }

        return implode("\n", $lines) . "\n";
    }

    private function username(PackageCredential $credential): string
    {
        if (filled($credential->username)) {
            return (string) $credential->username;
        }

        $host = self::host((string) $credential->target);

        return match (true) {
            str_contains($host, 'gitlab') => 'oauth2',
            $host === 'bitbucket.org' => 'x-token-auth',
            default => 'x-access-token',
        };
    }

    private function yaml(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function property(string $value, bool $key): string
    {
        $escaped = '';

        foreach (mb_str_split($value) as $index => $character) {
            $escaped .= match (true) {
                $character === '\\' => '\\\\',
                $character === '=' || $character === ':' => '\\' . $character,
                $key && ($character === ' ' || $character === '#' || $character === '!') => '\\' . $character,
                !$key && $index === 0 && $character === ' ' => '\\ ',
                mb_ord($character) > 0x7E || mb_ord($character) < 0x20 => sprintf('\\u%04x', mb_ord($character)),
                default => $character,
            };
        }

        return $escaped;
    }
}
