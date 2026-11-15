<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Packages;

use App\Enums\PackageSource;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Models\PackageCredential;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\EnvironmentFile;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;

final class StepSecretsInstaller
{
    public const ROOT = '/run/coresmith-steps';

    public const MOUNTS = [
        StepSecretsBuilder::NPMRC => 'npmrc',
        StepSecretsBuilder::YARNRC => 'yarnrc.yml',
        StepSecretsBuilder::NETRC => 'netrc',
        StepSecretsBuilder::MAVEN_SETTINGS => 'settings.xml',
        StepSecretsBuilder::GRADLE_PROPERTIES => 'gradle.properties',
    ];

    private const PLACEHOLDERS = [StepSecretsBuilder::MAVEN_SETTINGS => '<settings/>'];

    private const FILE_MARKER = '--coresmith-project-file:';

    private const PROJECT_FILE_LIMIT = 65536;

    public function __construct(
        private readonly PackageCredentials $credentials,
        private readonly StepSecretsBuilder $builder,
        private readonly EnvironmentFile $environmentFile,
    ) {}

    /**
     * @return list<string>
     */
    public function prepare(SSHConnection $connection, Application $application, string $unit, string $directory): array
    {
        $credentials = $this->credentials->forApplication($application);

        if ($credentials === []) {
            return [];
        }

        $unit = AppShell::assertUnit($unit);
        $usesNpm = array_filter($credentials, fn(PackageCredential $credential): bool => $credential->source === PackageSource::Npm) !== [];
        $secrets = $this->builder->build(
            $credentials,
            $this->environmentFile->parse((string) $application->env_variables),
            $usesNpm ? $this->projectFiles($connection, $application, $directory) : [],
        );

        if ($secrets->isEmpty()) {
            return [];
        }

        $connection->executeWithInput($this->script($application, $unit, $secrets), $this->payload($secrets), sudo: true)->throw();

        $folder = self::ROOT . '/' . $unit;
        $home = AppAccount::home($application);

        return [
            ...($secrets->environment === [] ? [] : ['EnvironmentFile=' . $folder . '.env']),
            ...array_map(fn(string $path): string => 'BindReadOnlyPaths=' . $folder . '/' . self::MOUNTS[$path] . ':' . $home . '/' . $path, array_keys($secrets->files)),
        ];
    }

    public function remove(SSHConnection $connection, string $unit): void
    {
        $folder = self::ROOT . '/' . AppShell::assertUnit($unit);

        $connection->execute('rm -rf -- ' . escapeshellarg($folder) . ' ' . escapeshellarg($folder . '.env'), sudo: true);
    }

    /**
     * @return array<string, string>
     */
    private function projectFiles(SSHConnection $connection, Application $application, string $directory): array
    {
        $user = SafeName::assertSystemUser($application->system_user);
        $script = 'cd ' . escapeshellarg($directory) . ' 2>/dev/null || exit 0; for f in ' . implode(' ', StepSecretsBuilder::PROJECT_FILES) . '; do if [ -f "$f" ]; then printf "\n' . self::FILE_MARKER . '%s\n" "$f"; head -c ' . self::PROJECT_FILE_LIMIT . ' -- "$f"; fi; done';
        $result = $connection->execute('runuser -u ' . $user . ' -- /bin/sh -c ' . escapeshellarg($script), sudo: true);

        if (!$result->successful()) {
            return [];
        }

        $files = [];

        foreach (array_slice(preg_split('/^' . preg_quote(self::FILE_MARKER, '/') . '/m', $result->output) ?: [], 1) as $chunk) {
            [$name, $content] = array_pad(explode("\n", $chunk, 2), 2, '');

            if (in_array($name, StepSecretsBuilder::PROJECT_FILES, true)) {
                $files[$name] = $content;
            }
        }

        return $files;
    }

    private function script(Application $application, string $unit, StepSecrets $secrets): string
    {
        $user = SafeName::assertSystemUser($application->system_user);
        $slug = (string) $application->slug;

        if (preg_match('/^[a-z0-9-]+\z/D', $slug) !== 1) {
            throw UnsafeNameException::slug($slug);
        }

        $root = self::ROOT;
        $folder = $root . '/' . $unit;
        $names = implode('|', self::MOUNTS);

        return implode("\n", [
            'set -eu',
            'umask 077',
            "install -d -m 700 -o root -g root {$root}",
            "find {$root} -mindepth 1 -maxdepth 1 -regextype posix-extended -regex '.*/coresmith-(build|blueprint)-{$slug}-[0-9]{8}-?[0-9]{6}(\\.env)?' -exec rm -rf -- {} +",
            "install -d -m 700 -o root -g root {$folder}",
            'while read -r name data; do',
            '  case "$name" in',
            "    environment) printf '%s' \"\$data\" | base64 -d > {$folder}.env ;;",
            "    {$names}) printf '%s' \"\$data\" | base64 -d > \"{$folder}/\$name\" && chown {$user}:{$user} \"{$folder}/\$name\" && chmod 400 \"{$folder}/\$name\" ;;",
            '  esac',
            'done',
            ...($secrets->files === [] ? [] : ['runuser -u ' . $user . ' -- /bin/sh -c ' . escapeshellarg($this->placeholders($application, array_keys($secrets->files)))]),
        ]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function placeholders(Application $application, array $paths): string
    {
        $folders = array_values(array_unique(array_filter(array_map(fn(string $path): string => dirname($path), $paths), fn(string $folder): bool => $folder !== '.')));
        $checks = array_map(
            fn(string $path): string => isset(self::PLACEHOLDERS[$path])
                ? "{ [ -e {$path} ] || printf \"%s\\n\" \"" . self::PLACEHOLDERS[$path] . "\" > {$path}; }"
                : "{ [ -e {$path} ] || : > {$path}; }",
            $paths,
        );

        return implode(' && ', ['umask 077', 'cd ' . AppAccount::home($application), ...($folders === [] ? [] : ['mkdir -p ' . implode(' ', $folders)]), ...$checks]);
    }

    private function payload(StepSecrets $secrets): string
    {
        $lines = $secrets->environment === [] ? [] : ['environment ' . base64_encode($this->environmentFile->render($secrets->environment))];

        foreach ($secrets->files as $path => $content) {
            $lines[] = self::MOUNTS[$path] . ' ' . base64_encode($content);
        }

        return implode("\n", $lines) . "\n";
    }
}
