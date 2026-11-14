<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Detection\Blocker;
use App\Services\Detection\StackChecks;
use App\Services\Detection\StackDetector;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;

function javaFacts(array $files, array $markers = ['mvnw', 'gradlew']): RepositoryFacts
{
    return new RepositoryFacts($files, markers: $markers, complete: true);
}

function javaPom(string $dependencies = '', string $extra = ''): string
{
    return "<project>\n  <modelVersion>4.0.0</modelVersion>\n  <groupId>com.acme</groupId>\n  <artifactId>shop</artifactId>\n  <version>1.0</version>\n{$extra}\n  <dependencies>\n{$dependencies}\n  </dependencies>\n</project>\n";
}

function javaDependency(string $group, string $artifact): string
{
    return "    <dependency>\n      <groupId>{$group}</groupId>\n      <artifactId>{$artifact}</artifactId>\n    </dependency>\n";
}

function javaBootPom(string $extra = ''): string
{
    return javaPom(javaDependency('org.springframework.boot', 'spring-boot-starter-web'), "  <parent>\n    <groupId>org.springframework.boot</groupId>\n    <artifactId>spring-boot-starter-parent</artifactId>\n    <version>3.5.7</version>\n  </parent>\n{$extra}");
}

function javaDetect(RepositoryFacts $facts): ?array
{
    return ApplicationType::Java->language()->detect($facts);
}

function javaToolingFor(RepositoryFacts $facts, string $framework): array
{
    return ApplicationType::Java->language()->tooling(new Application(['type' => ApplicationType::Java, 'framework' => $framework]), $facts);
}

function javaBlockers(RepositoryFacts $facts, string $framework): array
{
    return array_map(fn(Blocker $blocker): array => $blocker->toArray(), ApplicationType::Java->language()->checks(new Application(['type' => ApplicationType::Java, 'framework' => $framework]), $framework, $facts, 'x86_64'));
}

test('a Spring Boot app built with Maven is ready, and builds with its own wrapper', function () {
    $facts = javaFacts(['pom.xml' => javaBootPom()], ['mvnw']);

    expect(javaDetect($facts))->toBe([
        'type' => 'java', 'framework' => 'spring-boot', 'js_runtime' => null, 'web' => null,
        'sources' => ['type' => 'From pom.xml', 'framework' => 'org.springframework.boot in pom.xml'],
        'unsettled' => [],
    ])
        ->and(javaToolingFor($facts, 'spring-boot'))->toBe(['build' => 'maven', 'wrapper' => true, 'production' => false, 'dropwizard' => false, 'war' => false, 'application' => false, 'shadow' => false])
        ->and(javaBlockers($facts, 'spring-boot'))->toBe([]);
});

test('detection names the framework from the build file that declares it', function (array $files, string $framework, string $type, string $evidence) {
    $proposal = javaDetect(javaFacts($files));

    expect($proposal['type'])->toBe('java')
        ->and($proposal['framework'])->toBe($framework)
        ->and($proposal['sources'])->toBe(['type' => $type, 'framework' => $evidence])
        ->and($proposal['unsettled'])->toBe([]);
})->with([
    'Spring Boot with the Gradle Kotlin DSL' => [['build.gradle.kts' => "plugins {\n    java\n    id(\"org.springframework.boot\") version \"3.5.7\"\n}\n"], 'spring-boot', 'From build.gradle.kts', 'org.springframework.boot in build.gradle.kts'],
    'Quarkus' => [['pom.xml' => javaPom(javaDependency('io.quarkus', 'quarkus-rest') . javaDependency('io.quarkus', 'quarkus-vertx-http'))], 'quarkus', 'From pom.xml', 'io.quarkus in pom.xml'],
    'Micronaut' => [['build.gradle.kts' => "plugins {\n    id(\"io.micronaut.application\") version \"4.5.4\"\n}\n"], 'micronaut', 'From build.gradle.kts', 'io.micronaut in build.gradle.kts'],
    'Helidon' => [['pom.xml' => javaPom(javaDependency('io.helidon.webserver', 'helidon-webserver'), "  <parent>\n    <groupId>io.helidon.applications</groupId>\n    <artifactId>helidon-se</artifactId>\n  </parent>\n")], 'helidon', 'From pom.xml', 'io.helidon in pom.xml'],
    'Dropwizard' => [['pom.xml' => javaPom(javaDependency('io.dropwizard', 'dropwizard-core'))], 'dropwizard', 'From pom.xml', 'dropwizard-core in pom.xml'],
    'Javalin with Dropwizard Metrics' => [['pom.xml' => javaPom(javaDependency('io.javalin', 'javalin') . javaDependency('io.dropwizard.metrics', 'metrics-core'))], 'javalin', 'From pom.xml', 'io.javalin in pom.xml'],
    'Vert.x' => [['build.gradle' => "dependencies {\n    implementation 'io.vertx:vertx-web:5.0.5'\n}\n"], 'vertx', 'From build.gradle', 'io.vertx in build.gradle'],
    'plain Java' => [['pom.xml' => javaPom(javaDependency('com.google.guava', 'guava'))], 'plain', 'From pom.xml', 'no web framework in pom.xml'],
    'a gradle init layout with the app in a module' => [['settings.gradle.kts' => "rootProject.name = \"shop\"\ninclude(\"app\")\n", 'app/build.gradle.kts' => "plugins {\n    application\n    id(\"org.springframework.boot\") version \"3.5.7\"\n}\n"], 'spring-boot', 'From settings.gradle.kts', 'org.springframework.boot in app/build.gradle.kts'],
    "a Gradle version catalog's plugin alias" => [['settings.gradle.kts' => "rootProject.name = \"shop\"\n", 'build.gradle.kts' => "plugins {\n    alias(libs.plugins.spring.boot)\n    alias(libs.plugins.shadow)\n}\n", 'gradle/libs.versions.toml' => "[plugins]\nspring-boot = { id = \"org.springframework.boot\", version = \"3.5.7\" }\n"], 'spring-boot', 'From build.gradle.kts', 'org.springframework.boot in gradle/libs.versions.toml'],
    'a Maven build with the app in a module' => [['pom.xml' => javaPom('', "  <packaging>pom</packaging>\n  <modules>\n    <module>core</module>\n    <module>web</module>\n  </modules>\n"), 'core/pom.xml' => javaPom(javaDependency('com.google.guava', 'guava')), 'web/pom.xml' => javaPom(javaDependency('io.micronaut', 'micronaut-http-server-netty'))], 'micronaut', 'From pom.xml', 'io.micronaut in web/pom.xml'],
]);

test('a JHipster app is read from .yo-rc.json and builds with the prod profile, which bakes its frontend into the jar', function () {
    $facts = javaFacts([
        'pom.xml' => javaBootPom(),
        '.yo-rc.json' => json_encode(['generator-jhipster' => ['applicationType' => 'monolith', 'buildTool' => 'maven', 'clientFramework' => 'angular', 'prodDatabaseType' => 'postgresql']]),
        'package.json' => json_encode(['name' => 'shop', 'scripts' => ['build' => 'ng build']]),
    ], ['mvnw']);

    expect(javaDetect($facts))->toMatchArray(['type' => 'java', 'framework' => 'jhipster', 'sources' => ['type' => 'From pom.xml', 'framework' => 'generator-jhipster in .yo-rc.json']])
        ->and(javaToolingFor($facts, 'jhipster'))->toBe(['build' => 'maven', 'wrapper' => true, 'production' => true, 'dropwizard' => false, 'war' => false, 'application' => false, 'shadow' => false])
        ->and(javaBlockers($facts, 'jhipster'))->toBe([]);
});

test('a Java repository is read as Java before the package.json beside it, with the Java line its build names', function () {
    $facts = javaFacts(['pom.xml' => javaBootPom("  <properties>\n    <java.version>21</java.version>\n  </properties>\n"), 'package.json' => json_encode(['name' => 'shop', 'scripts' => ['build' => 'vite build']])], ['mvnw']);
    $detected = app(StackDetector::class)->detect($facts, fn(string $runtime): array => []);

    expect($detected->type)->toBe('java')
        ->and($detected->framework)->toBe('spring-boot')
        ->and($detected->blockers)->toBe([])
        ->and($detected->version())->toBe('21.0.13');
});

test('Dropwizard is started with its config file when the repository has one', function () {
    expect(javaToolingFor(javaFacts(['pom.xml' => javaPom(javaDependency('io.dropwizard', 'dropwizard-core'))], ['mvnw', 'config.yml']), 'dropwizard')['dropwizard'])->toBeTrue()
        ->and(javaToolingFor(javaFacts(['pom.xml' => javaPom(javaDependency('io.dropwizard', 'dropwizard-core'))], ['mvnw']), 'dropwizard')['dropwizard'])->toBeFalse();
});

test('a project that cannot build or run on a server is refused with what to change', function (array $files, array $markers, string $framework, array $blockers) {
    expect(javaBlockers(javaFacts($files, $markers), $framework))->toBe($blockers);
})->with([
    'Maven without its wrapper' => [['pom.xml' => javaBootPom()], [], 'spring-boot', [['field' => 'repository', 'reason' => "There is no mvnw at the repository's root, and Coresmith builds Java apps with the project's own Maven wrapper.", 'fix' => 'Run mvn wrapper:wrapper, then commit mvnw and the .mvn folder.']]],
    'Gradle without its wrapper' => [['build.gradle' => "plugins {\n    id 'org.springframework.boot' version '3.5.7'\n}\n"], [], 'spring-boot', [['field' => 'repository', 'reason' => "There is no gradlew at the repository's root, and Coresmith builds Java apps with the project's own Gradle wrapper.", 'fix' => 'Run gradle wrapper, then commit gradlew and the gradle folder.']]],
    'an Android app' => [['build.gradle' => "plugins {\n    id 'com.android.application'\n}\n"], ['gradlew'], 'plain', [['field' => 'repository', 'reason' => 'This is an Android app. It builds an APK for phones, and Coresmith runs apps on servers.', 'fix' => '']]],
    'a WAR for a servlet container' => [['pom.xml' => javaPom(javaDependency('jakarta.servlet', 'jakarta.servlet-api'), "  <packaging>war</packaging>\n")], ['mvnw'], 'plain', [['field' => 'repository', 'reason' => 'This app builds a WAR for a servlet container such as Tomcat, and Coresmith runs apps that start their own web server.', 'fix' => 'Package it as a jar that starts its own server, the way Spring Boot, Quarkus and Javalin apps do, then add it again.']]],
    'a Gradle WAR for a servlet container' => [['build.gradle' => "plugins {\n    id 'war'\n}\n"], ['gradlew'], 'plain', [['field' => 'repository', 'reason' => 'This app builds a WAR for a servlet container such as Tomcat, and Coresmith runs apps that start their own web server.', 'fix' => 'Package it as a jar that starts its own server, the way Spring Boot, Quarkus and Javalin apps do, then add it again.']]],
]);

test('a Spring Boot WAR runs on its own, so it is not refused', function () {
    $maven = javaFacts(['pom.xml' => javaBootPom("  <packaging>war</packaging>\n")], ['mvnw']);
    $gradle = javaFacts(['build.gradle' => "plugins {\n    id 'war'\n    id 'org.springframework.boot' version '3.5.7'\n}\n"], ['gradlew']);

    expect(javaBlockers($maven, 'spring-boot'))->toBe([])
        ->and(javaToolingFor($maven, 'spring-boot')['war'])->toBeTrue()
        ->and(javaBlockers($gradle, 'spring-boot'))->toBe([])
        ->and(javaToolingFor($gradle, 'spring-boot')['war'])->toBeTrue();
});

test("Gradle's application plugin and the shadow plugin are read from every build file, since they decide what the build leaves to run", function (array $files, bool $application, bool $shadow) {
    $tooling = javaToolingFor(javaFacts($files), 'plain');

    expect($tooling['application'])->toBe($application)
        ->and($tooling['shadow'])->toBe($shadow);
})->with([
    'the Kotlin DSL application plugin' => [['build.gradle.kts' => "plugins {\n    application\n}\n"], true, false],
    'the Groovy application plugin' => [['build.gradle' => "plugins {\n    id 'application'\n}\n"], true, false],
    'the old apply syntax' => [['build.gradle' => "apply plugin: 'application'\n"], true, false],
    "Micronaut's application plugin" => [['build.gradle.kts' => "plugins {\n    id(\"io.micronaut.application\") version \"4.5.4\"\n}\n"], true, false],
    "Ktor's plugin" => [['build.gradle.kts' => "plugins {\n    id(\"io.ktor.plugin\") version \"3.3.2\"\n}\n"], true, false],
    'an application in a module with the shadow plugin' => [['settings.gradle' => "include 'app'\n", 'app/build.gradle' => "plugins {\n    id 'application'\n    id 'com.gradleup.shadow' version '8.3.9'\n}\n"], true, true],
    'the shadow plugin from a version catalog' => [['build.gradle.kts' => "plugins {\n    alias(libs.plugins.shadow)\n}\n", 'gradle/libs.versions.toml' => "[plugins]\nshadow = { id = \"com.gradleup.shadow\", version = \"8.3.9\" }\n"], false, true],
    'the older shadow plugin alone' => [['build.gradle' => "plugins {\n    id 'java'\n    id 'com.github.johnrengelman.shadow' version '8.1.1'\n}\n"], false, true],
    'Maven' => [['pom.xml' => javaPom(javaDependency('io.javalin', 'javalin'))], false, false],
]);

test('a repository without a Maven or Gradle build is not a Java app', function () {
    expect(javaDetect(javaFacts(['Main.java' => "class Main {}\n"])))->toBeNull()
        ->and(javaDetect(javaFacts(['package.json' => '{}'])))->toBeNull();
});

test('a Java version Coresmith does not install is refused before the app is added', function () {
    $facts = javaFacts(['pom.xml' => javaBootPom("  <properties>\n    <java.version>26</java.version>\n  </properties>\n")], ['mvnw']);
    $blockers = app(StackChecks::class)->blockers(new Application(['directory' => '/', 'type' => ApplicationType::Java, 'framework' => 'spring-boot']), $facts, fn(string $runtime): array => []);

    expect(array_column(array_map(fn(Blocker $blocker): array => $blocker->toArray(), $blockers), 'field'))->toBe(['runtime_version'])
        ->and($blockers[0]->reason)->toContain('26');
});

test('the probe reads build files one folder down, so a module that holds the app is seen', function () {
    expect(RepositoryProbe::GLOBS)->toContain('*/pom.xml', '*/build.gradle', '*/build.gradle.kts');
});
