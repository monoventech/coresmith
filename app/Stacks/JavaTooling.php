<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final readonly class JavaTooling
{
    public const BUILDS = ['maven', 'gradle'];

    private const GRADLE_WAR = '/^\s*(?:id\s*\(?\s*["\']war["\']\s*\)?|apply\s+plugin:\s*["\']war["\']|war)\s*$/m';

    private const GRADLE_APPLICATION = '/^\s*(?:application\s*(?:\{.*)?$|id\s*\(?\s*["\'](?:application|io\.micronaut\.application|io\.ktor\.plugin)["\']|apply\s+plugin:\s*["\']application["\'])/m';

    private const GRADLE_SHADOW = '/com\.github\.johnrengelman\.shadow|com\.gradleup\.shadow|io\.github\.goooler\.shadow/';

    public function __construct(
        public string $build,
        public bool $wrapper,
        public bool $production,
        public bool $dropwizard,
        public bool $war = false,
        public bool $application = false,
        public bool $shadow = false,
    ) {}

    public static function for(string $framework, RepositoryFacts $facts): ?self
    {
        $build = match (JavaFrameworks::rootFile($facts)) {
            null => null,
            'pom.xml' => 'maven',
            default => 'gradle',
        };

        if ($build === null) {
            return null;
        }

        $gradle = $build === 'maven' ? '' : implode("\n", array_filter(JavaFrameworks::buildFiles($facts), fn(string $file): bool => str_contains($file, 'build.gradle'), ARRAY_FILTER_USE_KEY));

        return new self(
            $build,
            $facts->exists($build === 'maven' ? 'mvnw' : 'gradlew'),
            $framework === 'jhipster',
            $framework === 'dropwizard' && $facts->exists('config.yml'),
            $build === 'maven'
                ? preg_match('#<packaging>\s*war\s*</packaging>#', (string) $facts->file('pom.xml')) === 1
                : preg_match(self::GRADLE_WAR, (string) $facts->file('build.gradle') . "\n" . (string) $facts->file('build.gradle.kts')) === 1,
            preg_match(self::GRADLE_APPLICATION, $gradle) === 1,
            preg_match(self::GRADLE_SHADOW, $gradle) === 1,
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if (!is_array($data) || !in_array($data['build'] ?? null, self::BUILDS, true)) {
            return null;
        }

        $flag = fn(string $key): bool => ($data[$key] ?? false) === true;

        return new self((string) $data['build'], $flag('wrapper'), $flag('production'), $flag('dropwizard'), $flag('war'), $flag('application'), $flag('shadow'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['build' => $this->build, 'wrapper' => $this->wrapper, 'production' => $this->production, 'dropwizard' => $this->dropwizard, 'war' => $this->war, 'application' => $this->application, 'shadow' => $this->shadow];
    }

    public function output(): string
    {
        return $this->build === 'maven' ? 'target' : 'build/libs';
    }

    public function gradleTasks(string $framework): string
    {
        if (in_array($framework, ['spring-boot', 'jhipster'], true)) {
            return $this->war ? 'bootWar' : 'bootJar';
        }

        return 'assemble' . ($this->application ? ' installDist' : ($this->shadow ? ' shadowJar' : ''));
    }
}
