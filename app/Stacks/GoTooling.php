<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

final readonly class GoTooling
{
    public const PACKAGE = '~^(?:\.|[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*)\z~';

    public const BINARY = '/^[A-Za-z0-9_][A-Za-z0-9_.-]*\z/';

    public const FLAG = '/^--?[a-z][\w-]*=(?:127\.0\.0\.1:)?\$\{PORT\}\z/';

    public const TEMPL = '/^(?:tool|v\d+\.\d+\.\d+(?:-[\w.]+)?)\z/';

    public const VERSION = '/^v\d+\.\d+\.\d+(?:-[\w.]+)?\z/';

    /**
     * @param  array<string, string>  $mains
     * @param  array<string, string>  $listen
     */
    public function __construct(
        public array $mains,
        public ?string $web,
        public bool $vendored,
        public ?string $templ,
        public array $listen,
        public ?string $flag,
        public bool $grpc,
        public ?string $goose,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if (!is_array($data) || !is_array($data['mains'] ?? null)) {
            return null;
        }

        $mains = [];

        foreach ($data['mains'] as $package => $binary) {
            if (is_string($package) && is_string($binary) && preg_match(self::PACKAGE, $package) === 1 && !str_contains($package, '..') && preg_match(self::BINARY, $binary) === 1) {
                $mains[$package] = $binary;
            }
        }

        $web = is_string($data['web'] ?? null) && isset($mains[$data['web']]) ? $data['web'] : null;
        $listen = array_filter((array) ($data['listen'] ?? []), fn(mixed $kind, int|string $name): bool => is_string($name) && isset(GoSource::VARIABLES[$name]) && $kind === GoSource::VARIABLES[$name], ARRAY_FILTER_USE_BOTH);
        $flag = is_string($data['flag'] ?? null) && preg_match(self::FLAG, $data['flag']) === 1 ? $data['flag'] : null;
        $templ = is_string($data['templ'] ?? null) && preg_match(self::TEMPL, $data['templ']) === 1 ? $data['templ'] : null;
        $goose = is_string($data['goose'] ?? null) && preg_match(self::VERSION, $data['goose']) === 1 ? $data['goose'] : null;

        return new self($mains, $web, ($data['vendored'] ?? false) === true, $templ, $listen, $flag, ($data['grpc'] ?? false) === true, $goose);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mains' => $this->mains,
            'web' => $this->web,
            'vendored' => $this->vendored,
            'templ' => $this->templ,
            'listen' => $this->listen,
            'flag' => $this->flag,
            'grpc' => $this->grpc,
            'goose' => $this->goose,
        ];
    }

    public function webBinary(): ?string
    {
        return $this->web === null ? null : $this->mains[$this->web];
    }

    /**
     * @param  list<string>  $packages
     * @return array<string, string>
     */
    public static function binaries(array $packages, string $moduleName): array
    {
        $binaries = [];

        foreach ($packages as $package) {
            $name = $package === '.' ? $moduleName : (string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', basename($package));
            $name = preg_match(self::BINARY, $name) === 1 ? $name : 'app';
            $unique = $name;

            for ($count = 2; in_array($unique, $binaries, true); $count++) {
                $unique = "{$name}-{$count}";
            }

            $binaries[$package] = $unique;
        }

        return $binaries;
    }
}
