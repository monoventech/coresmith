<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\NativeTools;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;

final readonly class Java implements Language
{
    public const LAUNCHER = '.coresmith/app';

    public const HEAP = '-XX:MaxRAMPercentage=75';

    public const JHIPSTER_SECRET = 'JHIPSTER_SECURITY_AUTHENTICATION_JWT_BASE64_SECRET';

    private const LAUNCH = <<<'BASH'
    mkdir -p .coresmith && rm -f .coresmith/app
    set -- $(ls -1 target/quarkus-app/quarkus-run.jar build/quarkus-app/quarkus-run.jar */target/quarkus-app/quarkus-run.jar */build/quarkus-app/quarkus-run.jar 2>/dev/null)
    if [ "$#" -gt 1 ]; then echo "Coresmith found $# Quarkus apps ($*) and runs exactly one. Build only the app the server should run, then deploy again."; exit 1; fi
    if [ "$#" -eq 1 ]; then printf '#!/bin/sh\nexec java $JAVA_OPTS -jar "$(dirname "$0")/../%s" "$@"\n' "$1" > .coresmith/app && chmod +x .coresmith/app; exit $?; fi
    set -- $(ls -1 build/install/*/bin/* */build/install/*/bin/* 2>/dev/null | grep -v '\.bat$')
    if [ "$#" -gt 1 ]; then echo "Coresmith found $# start scripts ($*) and runs exactly one. Build only the app the server should run, then deploy again."; exit 1; fi
    if [ "$#" -eq 1 ]; then printf '#!/bin/sh\nexec "$(dirname "$0")/../%s" "$@"\n' "$1" > .coresmith/app && chmod +x .coresmith/app; exit $?; fi
    found=""
    for f in $(ls -1 {output}/*.jar {output}/*.war */{output}/*.jar */{output}/*.war 2>/dev/null | grep -vE '(-sources|-javadoc|-plain|-tests)\.(jar|war)$|(^|/)original-'); do
      t=$(mktemp -d); abs="$PWD/$f"
      if (cd "$t" && "$JAVA_HOME/bin/jar" xf "$abs" META-INF/MANIFEST.MF 2>/dev/null) && grep -q '^Main-Class:' "$t/META-INF/MANIFEST.MF" 2>/dev/null; then found="$found $f"; fi
      rm -rf "$t"
    done
    set -- $found
    if [ "$#" -eq 0 ]; then echo "Coresmith found no runnable jar in {output}. Package the app as a jar whose manifest names its Main-Class, then deploy again."; exit 1; fi
    if [ "$#" -gt 1 ]; then echo "Coresmith found $# runnable jars ($*) and runs exactly one. Build only the jar the app starts from, then deploy again."; exit 1; fi
    printf '#!/bin/sh\nexec java $JAVA_OPTS -jar "$(dirname "$0")/../%s" "$@"\n' "$1" > .coresmith/app && chmod +x .coresmith/app
    BASH;

    private const COMPILE_ERROR = '#^(?:\[ERROR\] |e: )?(?:file://)?(?:\S*/releases/[^/\s]+/)?(\S+\.(?:java|kt|groovy|scala))(?::\[(\d+),\d+\]|:(\d+):(?:\d+:?)?(?: error:)?) (.+)$#m';

    private const RELEASE_WANTED = '/release version (\d+) not supported|invalid target release: (?:1\.)?(\d+)|languageVersion=(\d+)/';

    public function __construct(private AsdfManager $asdf, private JavaDetection $detection, private JavaChecks $checks) {}

    public function install(Application $application, Stack $stack): array
    {
        return [];
    }

    public function build(Application $application, Stack $stack): array
    {
        $tooling = JavaTooling::fromArray($stack->languageTooling);
        $home = $this->home($stack);

        if ($tooling === null || $home === null) {
            return [];
        }

        $profile = $tooling->production ? ' -Pprod' : '';
        $build = $tooling->build === 'maven'
            ? "sh ./mvnw -B{$profile} -DskipTests package"
            : "sh ./gradlew --no-daemon{$profile} -x test " . $tooling->gradleTasks((string) $application->framework);

        return ["JAVA_HOME={$home} {$build}", "JAVA_HOME={$home} sh -c " . escapeshellarg(strtr(self::LAUNCH, ['{output}' => $tooling->output()]))];
    }

    public function migrations(Application $application, Stack $stack): array
    {
        return [];
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        return $this->saved($application) === null ? [] : [self::LAUNCHER => 'test -x ' . escapeshellarg($paths->appDirectory($release) . '/' . self::LAUNCHER)];
    }

    public function webCommand(Application $application): ?string
    {
        $tooling = $this->saved($application);
        $version = $application->java_version;

        if ($tooling === null || !is_string($version) || $version === '') {
            return null;
        }

        $paths = new ReleasePaths($application);

        return $paths->appDirectory($paths->current()) . '/' . self::LAUNCHER . ($tooling->dropwizard ? ' server config.yml' : '');
    }

    public function usesFpm(): bool
    {
        return false;
    }

    public function route(Application $application): Route
    {
        return Route::ReverseProxy;
    }

    public function storageSeed(): StorageSeed
    {
        return StorageSeed::None;
    }

    public function initialEnvironment(?string $framework): array
    {
        return $framework === 'jhipster' ? [self::JHIPSTER_SECRET => base64_encode(random_bytes(64))] : [];
    }

    public function processEnvironment(Application $application): array
    {
        return ['JAVA_OPTS' => self::HEAP];
    }

    public function listenEnvironment(Application $application): array
    {
        $port = (string) $application->base_port;
        $listen = JavaFrameworks::listen((string) $application->framework);

        return [
            'PORT' => $port,
            'HOST' => '127.0.0.1',
            ...(isset($listen['port']) ? [$listen['port'] => $port] : []),
            ...(isset($listen['host']) ? [$listen['host'] => '127.0.0.1'] : []),
        ];
    }

    public function detect(RepositoryFacts $facts): ?array
    {
        return $this->detection->detect($facts);
    }

    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        return $this->checks->checks($framework, $facts);
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        return null;
    }

    public function cronExample(Application $application): string
    {
        return 'sh scripts/nightly.sh';
    }

    public function tooling(Application $application, RepositoryFacts $facts): array
    {
        return JavaTooling::for((string) $application->framework, $facts)?->toArray() ?? [];
    }

    public function pathDirectories(string $appDirectory): array
    {
        return [];
    }

    public function nativeTools(): ?NativeTools
    {
        return null;
    }

    public function frameworkLabel(string $framework): ?string
    {
        return JavaFrameworks::label($framework);
    }

    public function explain(string $phase, string $output, Stack $stack): ?string
    {
        if ($phase !== DeploymentPhases::BUILD) {
            return null;
        }

        if (preg_match('/^Coresmith found .+ then deploy again\.$/m', $output, $jar) === 1) {
            return $jar[0];
        }

        if (str_contains($output, '* What went wrong:') && preg_match('/Unsupported class file major version (\d+)/', $output, $major) === 1) {
            $java = (int) $major[1] - 44;

            return "The project's Gradle is too old to run on Java {$java}. Update its Gradle wrapper to a version that supports Java {$java}, commit it, then deploy again.";
        }

        if (preg_match(self::RELEASE_WANTED, $output, $release) === 1) {
            $wanted = (string) max(array_slice($release, 1));
            $running = explode('.', (string) ($stack->versions()['java'] ?? ''))[0];

            return "The build targets Java {$wanted}, and the app runs Java {$running}. Set the app's Java to {$wanted} or the build's release to {$running}, then deploy again.";
        }

        if (preg_match(self::COMPILE_ERROR, $output, $compile) === 1) {
            $line = $compile[2] !== '' ? $compile[2] : $compile[3];

            return "The Java build stopped at {$compile[1]} line {$line}: " . rtrim(trim($compile[4]), '.') . '. Fix it, then deploy again.';
        }

        if (preg_match('/^\[ERROR\] Failed to execute goal (?:\S+ \(\S+\) )?on project \S+: (.+?)(?: -> \[Help \d+\])?$/m', $output, $goal) === 1) {
            return 'The Maven build failed: ' . rtrim(trim($goal[1]), '.') . '. Fix it, then deploy again.';
        }

        if (preg_match('/\* What went wrong:\r?\n(.+?)(?:\r?\n\s*\r?\n|\r?\n\* Try:|\z)/s', $output, $gradle) === 1) {
            $lines = array_map(trim(...), preg_split('/\r\n|\n|\r/', trim($gradle[1])) ?: []);
            $causes = array_values(array_filter($lines, fn(string $line): bool => str_starts_with($line, '>')));
            $cause = $causes === [] ? $lines[0] : ltrim(end($causes), '> ');

            return 'The Gradle build failed: ' . rtrim($cause, '.') . '. Fix it, then deploy again.';
        }

        if (preg_match('/^\[ERROR\] (?!COMPILATION ERROR)(\S.+)$/m', $output, $maven) === 1) {
            return 'The Maven build failed: ' . rtrim(trim($maven[1]), '.') . '. Fix it, then deploy again.';
        }

        return null;
    }

    private function home(Stack $stack): ?string
    {
        $version = $stack->versions()['java'] ?? null;

        return $version === null ? null : $this->asdf->versionPath('java', $version);
    }

    private function saved(Application $application): ?JavaTooling
    {
        $data = ((array) $application->stack)['language_tooling'] ?? null;

        return JavaTooling::fromArray(is_array($data) ? $data : null);
    }
}
