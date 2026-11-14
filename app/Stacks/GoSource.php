<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final readonly class GoSource
{
    public const SKIPPED = ['examples', 'example', '_examples', 'test', 'tests', 'testdata', 'tools', 'scripts', 'hack', 'vendor', 'third_party', 'mocks', 'docs'];

    public const VARIABLES = [
        'HTTP_PORT' => 'port', 'SERVER_PORT' => 'port', 'APP_PORT' => 'port', 'WEB_PORT' => 'port', 'API_PORT' => 'port', 'LISTEN_PORT' => 'port',
        'ADDR' => 'address', 'ADDRESS' => 'address', 'HTTP_ADDR' => 'address', 'HTTP_ADDRESS' => 'address', 'LISTEN_ADDR' => 'address', 'LISTEN_ADDRESS' => 'address',
        'SERVER_ADDR' => 'address', 'SERVER_ADDRESS' => 'address', 'BIND_ADDR' => 'address', 'BIND_ADDRESS' => 'address', 'APP_ADDR' => 'address', 'HTTP_LISTEN_ADDR' => 'address',
    ];

    public const FLAGS = [
        'port' => 'port', 'http-port' => 'port', 'http_port' => 'port',
        'addr' => 'address', 'address' => 'address', 'listen' => 'address', 'http' => 'address', 'bind' => 'address',
        'http-addr' => 'address', 'http-address' => 'address', 'listen-addr' => 'address', 'listen-address' => 'address',
    ];

    private const LISTENS = '/ListenAndServe(?:TLS)?\(|\.Run\(\s*(?:\)|"|[\w.]*(?:addr|Addr|port|Port))|\.Start\(\s*(?:"|[\w.]*(?:addr|Addr|port|Port))|\.Listen\(\s*(?:"|[\w.]*(?:addr|Addr|port|Port))|\.Serve\(|WithHostPorts\(|grpc\.NewServer\(/';

    private const HTTP = '/ListenAndServe(?:TLS)?\(|\.Run\(\s*(?:\)|"|[\w.]*(?:addr|Addr|port|Port))|\.Start\(\s*(?:"|[\w.]*(?:addr|Addr|port|Port))|WithHostPorts\(|http\.Serve\(/';

    private const LITERAL_PORT = '/"(?:[A-Za-z0-9.\-]*|\[[0-9A-Fa-f:]*\])?:(\d{2,5})"/';

    /**
     * @param  array<string, list<array{int, string}>>  $lines
     */
    public function __construct(private array $lines) {}

    public static function from(RepositoryFacts $facts): self
    {
        return new self($facts->goLines);
    }

    /**
     * @return list<string>
     */
    public function mains(): array
    {
        $mains = [];

        foreach ($this->packages() as $directory => $package) {
            if ($package['name'] === 'main' && !$this->skipped($directory)) {
                $mains[] = $directory;
            }
        }

        usort($mains, fn(string $a, string $b): int => [$a !== '.', !str_starts_with($a, 'cmd/'), $a] <=> [$b !== '.', !str_starts_with($b, 'cmd/'), $b]);

        return $mains;
    }

    public function serves(string $directory): bool
    {
        return $this->matching($directory, self::LISTENS) !== [];
    }

    public function grpcServer(?string $directory = null): bool
    {
        return $this->matching($directory, '/grpc\.NewServer\(/') !== [];
    }

    public function grpcOnly(string $directory): bool
    {
        return $this->grpcServer($directory) && $this->matching($directory, self::HTTP) === [];
    }

    /**
     * @return list<array{string, int, string}>
     */
    public function hardcoded(string $directory): array
    {
        return array_values(array_filter(
            $this->matching($directory, self::LISTENS),
            fn(array $line): bool => preg_match(self::LITERAL_PORT, $line[2]) === 1 && preg_match('/Getenv|LookupEnv/', $line[2]) !== 1,
        ));
    }

    public function readsPort(): bool
    {
        return $this->matching(null, '/(?:Getenv|LookupEnv)\(\s*"PORT"\s*\)|(?:env|envconfig):"PORT[",]|\.Run\(\s*\)|AutomaticEnv\(/') !== [];
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        $found = [];

        foreach ($this->matching(null, '/(?:Getenv|LookupEnv)\(\s*"[A-Z_]+"|(?:env|envconfig):"[A-Z_]+/') as [, , $text]) {
            preg_match_all('/(?:Getenv|LookupEnv)\(\s*"([A-Z_]+)"|(?:env|envconfig):"([A-Z_]+)/', $text, $names, PREG_SET_ORDER);

            foreach ($names as $name) {
                $variable = $name[1] !== '' ? $name[1] : ($name[2] ?? '');

                if (isset(self::VARIABLES[$variable])) {
                    $found[$variable] = self::VARIABLES[$variable];
                }
            }
        }

        ksort($found);

        return $found;
    }

    public function flag(string $directory): ?string
    {
        foreach ($this->matching($directory, '/\bp?flag\.(?:String|Int|Uint)(?:Var)?P?\(/') as [, , $text]) {
            if (preg_match('/\b(p?flag)\.(?:String|Int|Uint)(?:Var)?P?\(\s*(?:&[\w.]+\s*,\s*)?"([\w-]+)"/', $text, $matches) !== 1 || !isset(self::FLAGS[$matches[2]])) {
                continue;
            }

            $dashes = $matches[1] === 'pflag' ? '--' : '-';
            $value = self::FLAGS[$matches[2]] === 'port' ? '${PORT}' : '127.0.0.1:${PORT}';

            return "{$dashes}{$matches[2]}={$value}";
        }

        return null;
    }

    public function mentionsAddress(): bool
    {
        return $this->matching(null, '/(?:Getenv|LookupEnv)\(\s*"[A-Z_]*(?:PORT|ADDR|LISTEN|BIND)[A-Z_]*"|(?:env|envconfig):"[A-Z_]*(?:PORT|ADDR|LISTEN|BIND)[A-Z_]*"|flag\.\w+\((?:&[\w.]+\s*,\s*)?"[\w-]*(?:port|addr|listen|bind)[\w-]*"/i') !== [];
    }

    public function cgo(): bool
    {
        return $this->matching(null, '/^import "C"$|^"C"$/') !== [];
    }

    /**
     * @return list<array{string, int, string}>
     */
    private function matching(?string $directory, string $pattern): array
    {
        $found = [];

        foreach ($this->packages() as $name => $package) {
            if ($directory !== null && $name !== $directory) {
                continue;
            }

            foreach ($package['lines'] as $line) {
                if (preg_match($pattern, $line[2]) === 1) {
                    $found[] = $line;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, array{name: ?string, lines: list<array{string, int, string}>}>
     */
    private function packages(): array
    {
        $packages = [];

        foreach ($this->lines as $file => $lines) {
            if ($this->ignored($lines)) {
                continue;
            }

            $directory = str_contains($file, '/') ? dirname($file) : '.';
            $packages[$directory] ??= ['name' => null, 'lines' => []];

            foreach ($lines as [$number, $text]) {
                if ($packages[$directory]['name'] === null && preg_match('/^package\s+(\w+)/', $text, $matches) === 1) {
                    $packages[$directory]['name'] = $matches[1];
                }

                $packages[$directory]['lines'][] = [$file, $number, $text];
            }
        }

        return $packages;
    }

    /**
     * @param  list<array{int, string}>  $lines
     */
    private function ignored(array $lines): bool
    {
        foreach ($lines as [, $text]) {
            if (preg_match('~^//go:build\s+(?:ignore|tools)\s*$~', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function skipped(string $directory): bool
    {
        if ($directory === '.') {
            return false;
        }

        foreach (explode('/', $directory) as $part) {
            if (in_array($part, self::SKIPPED, true) || str_starts_with($part, '_') || str_starts_with($part, '.')) {
                return true;
            }
        }

        return false;
    }
}
