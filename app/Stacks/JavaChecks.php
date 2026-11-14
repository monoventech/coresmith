<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Detection\Blocker;
use App\Services\Runtime\RepositoryFacts;

final readonly class JavaChecks
{
    private const RUNNABLE_WARS = ['spring-boot', 'jhipster'];

    /**
     * @return list<Blocker>
     */
    public function checks(string $framework, RepositoryFacts $facts): array
    {
        $tooling = JavaTooling::for($framework, $facts);

        if ($tooling === null) {
            return [new Blocker('repository', "There is no pom.xml or build.gradle {$facts->at()}, so Coresmith can't tell how to build the app.", 'Commit the Maven or Gradle build file.')];
        }

        if (str_contains(implode("\n", JavaFrameworks::buildFiles($facts)), 'com.android.application')) {
            return [new Blocker('repository', 'This is an Android app, which builds an APK for phones rather than a server.', '')];
        }

        if ($tooling->war && !in_array($framework, self::RUNNABLE_WARS, true)) {
            return [new Blocker('repository', 'This app builds a WAR for a servlet container such as Tomcat, and Coresmith runs apps that start their own web server.', 'Package it as an executable jar with an embedded server, as Spring Boot, Quarkus or Javalin apps are, then add it again.')];
        }

        if (!$tooling->wrapper) {
            return [$tooling->build === 'maven'
                ? new Blocker('repository', "There is no mvnw {$facts->at()}, and Coresmith builds Java apps with the project's own Maven wrapper.", 'Run mvn wrapper:wrapper, then commit mvnw and the .mvn folder.')
                : new Blocker('repository', "There is no gradlew {$facts->at()}, and Coresmith builds Java apps with the project's own Gradle wrapper.", 'Run gradle wrapper, then commit gradlew and the gradle folder.')];
        }

        return [];
    }
}
