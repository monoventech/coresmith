<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final class JavaFrameworks
{
    public const PLAIN = 'plain';

    public const ROOT_BUILDS = ['pom.xml', 'build.gradle.kts', 'build.gradle'];

    public const SETTINGS = ['settings.gradle.kts', 'settings.gradle'];

    public const MODULE_BUILDS = ['*/pom.xml', '*/build.gradle.kts', '*/build.gradle'];

    public const ALL = [
        'spring-boot' => ['Spring Boot', ['org.springframework.boot', 'spring-boot'], ['port' => 'SERVER_PORT', 'host' => 'SERVER_ADDRESS']],
        'jhipster' => ['JHipster', [], ['port' => 'SERVER_PORT', 'host' => 'SERVER_ADDRESS']],
        'quarkus' => ['Quarkus', ['io.quarkus'], ['port' => 'QUARKUS_HTTP_PORT', 'host' => 'QUARKUS_HTTP_HOST']],
        'micronaut' => ['Micronaut', ['io.micronaut'], ['port' => 'MICRONAUT_SERVER_PORT', 'host' => 'MICRONAUT_SERVER_HOST']],
        'helidon' => ['Helidon', ['io.helidon'], ['port' => 'SERVER_PORT', 'host' => 'SERVER_HOST']],
        'dropwizard' => ['Dropwizard', ['dropwizard-core'], []],
        'javalin' => ['Javalin', ['io.javalin'], []],
        'vertx' => ['Vert.x', ['io.vertx'], []],
        self::PLAIN => ['Plain Java', [], []],
    ];

    /**
     * @return array{0: string, 1: string}
     */
    public static function claim(RepositoryFacts $facts): array
    {
        if (self::jhipster($facts) !== null) {
            return ['jhipster', 'generator-jhipster in .yo-rc.json'];
        }

        $files = self::buildFiles($facts);

        foreach (self::ALL as $key => [, $markers]) {
            foreach ($files as $file => $content) {
                foreach ($markers as $marker) {
                    if (str_contains($content, $marker)) {
                        return [$key, "{$marker} in {$file}"];
                    }
                }
            }
        }

        return [self::PLAIN, 'no web framework in ' . (array_key_first($files) ?? self::rootFile($facts) ?? 'the build')];
    }

    public static function rootFile(RepositoryFacts $facts): ?string
    {
        foreach ([...self::ROOT_BUILDS, ...self::SETTINGS] as $file) {
            if ($facts->has($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function buildFiles(RepositoryFacts $facts): array
    {
        $files = [];

        foreach (self::ROOT_BUILDS as $file) {
            if ($facts->has($file)) {
                $files[$file] = (string) $facts->file($file);
            }
        }

        foreach (self::MODULE_BUILDS as $pattern) {
            foreach ($facts->matching($pattern) as $file => $content) {
                $files[$file] = (string) $content;
            }
        }

        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function jhipster(RepositoryFacts $facts): ?array
    {
        $config = $facts->json('.yo-rc.json')['generator-jhipster'] ?? null;

        return is_array($config) ? $config : null;
    }

    public static function label(string $key): ?string
    {
        return self::ALL[$key][0] ?? null;
    }

    /**
     * @return array{port?: string, host?: string}
     */
    public static function listen(string $key): array
    {
        return self::ALL[$key][2] ?? [];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn(string $key): array => ['value' => $key, 'label' => self::ALL[$key][0]], array_keys(self::ALL));
    }
}
