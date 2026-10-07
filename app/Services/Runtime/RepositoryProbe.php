<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Services\SSH\SSHConnection;

final class RepositoryProbe
{
    public const DIRECTORY_VARIABLE = 'CORESMITH_PROBE_DIR';

    public const FILES = ['.tool-versions', 'composer.json', 'package.json', '.nvmrc', '.node-version', '.ruby-version', 'Gemfile', '.dvmrc', 'deno.json', 'deno.jsonc'];

    public const LOCKFILES = ['bun.lock', 'bun.lockb', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'deno.lock'];

    public const MAX_BYTES = 262144;

    private const SCRIPT = <<<'BASH'
    cd "$CORESMITH_PROBE_DIR" 2>/dev/null || exit 0
    for f in {files}; do
      if [ -f "$f" ]; then printf '@@file %s ' "$f"; head -c {bytes} "$f" | base64 | tr -d '\n'; printf '\n'; fi
    done
    for f in {lockfiles}; do
      if [ -f "$f" ]; then printf '@@lock %s\n' "$f"; fi
    done
    if [ -f composer.lock ]; then
      grep -A1 -E '"name": "(laravel/framework|symfony/framework-bundle)",' composer.lock | awk -F'"' '/"name"/ {n=$4} /"version"/ {print "@@locked " n " " $4}'
    fi
    if [ -f Gemfile.lock ]; then
      sed -n 's/^    rails (\([^)]*\))$/@@locked rails \1/p' Gemfile.lock | head -n 1
    fi
    exit 0
    BASH;

    public function read(SSHConnection $connection, string $directory): RepositoryFacts
    {
        $command = 'echo ' . escapeshellarg(base64_encode($this->script())) . ' | base64 -d | '
            . self::DIRECTORY_VARIABLE . '=' . escapeshellarg($directory) . ' bash -s';

        return $this->parse($connection->execute($command)->output);
    }

    public function script(): string
    {
        return strtr(self::SCRIPT, [
            '{files}' => implode(' ', self::FILES),
            '{lockfiles}' => implode(' ', self::LOCKFILES),
            '{bytes}' => (string) self::MAX_BYTES,
        ]) . "\n";
    }

    public function parse(string $output): RepositoryFacts
    {
        $files = [];
        $lockfiles = [];
        $locked = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('~^@@file (\S+) ([A-Za-z0-9+/=]*)$~', $line, $matches) === 1 && in_array($matches[1], self::FILES, true)) {
                $content = base64_decode($matches[2], true);

                if ($content !== false) {
                    $files[$matches[1]] = $content;
                }
            } elseif (preg_match('/^@@lock (\S+)$/', $line, $matches) === 1 && in_array($matches[1], self::LOCKFILES, true)) {
                $lockfiles[] = $matches[1];
            } elseif (preg_match('/^@@locked (\S+) v?(\d[\w.+-]*)$/', $line, $matches) === 1) {
                $locked[$matches[1]] = $matches[2];
            }
        }

        return new RepositoryFacts($files, array_values(array_unique($lockfiles)), $locked);
    }
}
