<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Deployment\ReleasePaths;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Stacks\JavaTooling;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const JAVA_HOME_25 = '/opt/asdf-data/installs/java/25.0.5';

function javaDeployTooling(array $changes = []): JavaTooling
{
    return JavaTooling::fromArray([...(new JavaTooling('maven', true, false, false))->toArray(), ...$changes]);
}

function javaDeployStack(?JavaTooling $tooling = null): Stack
{
    return new Stack(['java' => new ResolvedRuntime('java', '25.0.5', 'pom.xml', '25', true)], languageTooling: ($tooling ?? javaDeployTooling())->toArray());
}

function javaDeployApp(array $attributes = [], ?JavaTooling $tooling = null): Application
{
    return new Application(['type' => ApplicationType::Java, 'framework' => 'spring-boot', 'slug' => 'shop', 'system_user' => 'coresmith-app-shop', 'java_version' => '25.0.5', 'base_port' => 4500, 'app_size' => 'medium', 'stack' => ['language_tooling' => ($tooling ?? javaDeployTooling())->toArray()], ...$attributes]);
}

function javaJar(string $path, ?string $mainClass): void
{
    File::ensureDirectoryExists(dirname($path));
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\n" . ($mainClass === null ? '' : "Main-Class: {$mainClass}\n"));
    $zip->close();
}

function javaLaunch(array $files, string $build = 'maven', array $arguments = ['server']): array
{
    $directory = sys_get_temp_dir() . '/coresmith-java-' . bin2hex(random_bytes(4));
    $tools = $directory . '-tools';
    File::ensureDirectoryExists($directory);
    File::ensureDirectoryExists($tools . '/bin');
    File::put($tools . '/bin/jar', "#!/bin/sh\n[ \"\$1\" = xf ] || exit 2\nunzip -qo \"\$2\" \"\$3\"\n");
    File::put($tools . '/bin/java', "#!/bin/sh\necho \"java \$*\"\n");
    chmod($tools . '/bin/jar', 0755);
    chmod($tools . '/bin/java', 0755);

    foreach ($files as $path => $content) {
        if (str_ends_with($path, '.jar') || str_ends_with($path, '.war')) {
            javaJar($directory . '/' . $path, $content);
        } else {
            File::ensureDirectoryExists(dirname($directory . '/' . $path));
            File::put($directory . '/' . $path, (string) $content);
            chmod($directory . '/' . $path, 0755);
        }
    }

    $launch = ApplicationType::Java->language()->build(javaDeployApp(['framework' => 'plain']), javaDeployStack(javaDeployTooling(['build' => $build])))[1];
    $result = Process::path($directory)->run(str_replace('JAVA_HOME=' . JAVA_HOME_25 . ' ', 'JAVA_HOME=' . $tools . ' ', $launch));
    $runs = is_executable($directory . '/.coresmith/app')
        ? Process::path('/')->env(['PATH' => $tools . '/bin:/usr/bin:/bin', 'JAVA_OPTS' => '-XX:MaxRAMPercentage=75'])->run([$directory . '/.coresmith/app', ...$arguments])->output()
        : null;
    File::deleteDirectory($directory);
    File::deleteDirectory($tools);

    return ['ok' => $result->successful(), 'output' => trim($result->output()), 'runs' => $runs === null ? null : trim(str_replace($directory . '/.coresmith/../', '', $runs))];
}

test('nothing installs before the build, and Coresmith runs no migrations, since Flyway and Liquibase migrate when the app starts', function () {
    expect(ApplicationType::Java->language()->install(javaDeployApp(), javaDeployStack()))->toBe([])
        ->and(ApplicationType::Java->language()->migrations(javaDeployApp(), javaDeployStack()))->toBe([]);
});

test("the build runs the project's own wrapper with the app's Java, skips the tests, then writes the launcher", function (string $framework, array $tooling, string $build) {
    $commands = ApplicationType::Java->language()->build(javaDeployApp(['framework' => $framework]), javaDeployStack(javaDeployTooling($tooling)));

    expect($commands[0])->toBe('JAVA_HOME=' . JAVA_HOME_25 . ' ' . $build)
        ->and(count($commands))->toBe(2)
        ->and($commands[1])->toStartWith('JAVA_HOME=' . JAVA_HOME_25 . " sh -c '");
})->with([
    'Spring Boot with Maven' => ['spring-boot', [], 'sh ./mvnw -B -DskipTests package'],
    'Spring Boot with Gradle' => ['spring-boot', ['build' => 'gradle'], 'sh ./gradlew --no-daemon -x test bootJar'],
    'JHipster with Maven' => ['jhipster', ['production' => true], 'sh ./mvnw -B -Pprod -DskipTests package'],
    'JHipster with Gradle' => ['jhipster', ['build' => 'gradle', 'production' => true], 'sh ./gradlew --no-daemon -Pprod -x test bootJar'],
    'Micronaut with Gradle' => ['micronaut', ['build' => 'gradle'], 'sh ./gradlew --no-daemon -x test assemble'],
    "Gradle's application plugin" => ['micronaut', ['build' => 'gradle', 'application' => true], 'sh ./gradlew --no-daemon -x test assemble installDist'],
    'a Gradle shadow jar' => ['javalin', ['build' => 'gradle', 'shadow' => true], 'sh ./gradlew --no-daemon -x test assemble shadowJar'],
    'a Spring Boot WAR with Gradle' => ['spring-boot', ['build' => 'gradle', 'war' => true], 'sh ./gradlew --no-daemon -x test bootWar'],
    'Quarkus with Maven' => ['quarkus', [], 'sh ./mvnw -B -DskipTests package'],
]);

test('the build needs a saved build tool and a Java version', function () {
    expect(ApplicationType::Java->language()->build(javaDeployApp(), new Stack([], languageTooling: javaDeployTooling()->toArray())))->toBe([])
        ->and(ApplicationType::Java->language()->build(javaDeployApp(), new Stack(['java' => new ResolvedRuntime('java', '25.0.5', 'pom.xml', '25', true)])))->toBe([]);
});

test('the launcher runs the one jar whose manifest names a Main-Class, never a sources, javadoc, plain, tests or original jar', function (array $files, string $build, string $runs) {
    $launched = javaLaunch($files, $build);

    expect($launched['ok'])->toBeTrue()
        ->and($launched['runs'])->toBe($runs);
})->with([
    "Spring Boot's repackaged jar" => [['target/shop-1.0.jar' => 'org.springframework.boot.loader.launch.JarLauncher', 'target/shop-1.0-sources.jar' => 'com.acme.Shop'], 'maven', 'java -XX:MaxRAMPercentage=75 -jar target/shop-1.0.jar server'],
    'a shaded jar beside the original' => [['target/shop-1.0.jar' => 'com.acme.Shop', 'target/original-shop-1.0.jar' => 'com.acme.Shop', 'target/shop-1.0-javadoc.jar' => null], 'maven', 'java -XX:MaxRAMPercentage=75 -jar target/shop-1.0.jar server'],
    "Gradle's boot jar beside the plain one" => [['build/libs/shop-1.0.jar' => 'org.springframework.boot.loader.launch.JarLauncher', 'build/libs/shop-1.0-plain.jar' => 'com.acme.Shop'], 'gradle', 'java -XX:MaxRAMPercentage=75 -jar build/libs/shop-1.0.jar server'],
    'a shadow jar beside the thin one' => [['build/libs/shop-1.0-all.jar' => 'com.acme.Application', 'build/libs/shop-1.0.jar' => null], 'gradle', 'java -XX:MaxRAMPercentage=75 -jar build/libs/shop-1.0-all.jar server'],
    "Spring Boot's executable WAR beside Gradle's plain one" => [['build/libs/shop-1.0.war' => 'org.springframework.boot.loader.launch.WarLauncher', 'build/libs/shop-1.0-plain.war' => null], 'gradle', 'java -XX:MaxRAMPercentage=75 -jar build/libs/shop-1.0.war server'],
    'a module of a multi-module build' => [['web/target/web-1.0.jar' => 'com.acme.Web', 'core/target/core-1.0.jar' => null, 'web/target/web-1.0-tests.jar' => 'com.acme.WebTest'], 'maven', 'java -XX:MaxRAMPercentage=75 -jar web/target/web-1.0.jar server'],
]);

test("the launcher runs Quarkus's fast jar in place and an installed Gradle distribution through its own start script", function (array $files, string $build, string $runs) {
    $launched = javaLaunch($files, $build);

    expect($launched['ok'])->toBeTrue()
        ->and($launched['runs'])->toBe($runs);
})->with([
    'Quarkus with Maven' => [['target/quarkus-app/quarkus-run.jar' => 'io.quarkus.bootstrap.runner.QuarkusEntryPoint', 'target/getting-started-1.0.jar' => null], 'maven', 'java -XX:MaxRAMPercentage=75 -jar target/quarkus-app/quarkus-run.jar server'],
    'Quarkus with Gradle' => [['build/quarkus-app/quarkus-run.jar' => 'io.quarkus.bootstrap.runner.QuarkusEntryPoint'], 'gradle', 'java -XX:MaxRAMPercentage=75 -jar build/quarkus-app/quarkus-run.jar server'],
    "the application plugin's distribution" => [['build/install/hello/bin/hello' => "#!/bin/sh\necho \"start script \$JAVA_OPTS \$*\"\n", 'build/install/hello/bin/hello.bat' => '@echo off', 'build/libs/hello-0.1.jar' => 'hello.WebAppKt'], 'gradle', 'start script -XX:MaxRAMPercentage=75 server'],
    'a distribution in a module' => [['app/build/install/app/bin/app' => "#!/bin/sh\necho \"start script \$*\"\n"], 'gradle', 'start script server'],
]);

test('a build that leaves nothing to run, or several things, fails and says which', function (array $files, string $message) {
    $launched = javaLaunch($files);

    expect($launched['ok'])->toBeFalse()
        ->and($launched['output'])->toBe($message)
        ->and($launched['runs'])->toBeNull();
})->with([
    'no jar at all' => [[], 'Coresmith found no runnable jar in target. Package the app as a jar whose manifest names its Main-Class, then deploy again.'],
    'only a library jar' => [['target/shop-1.0.jar' => null], 'Coresmith found no runnable jar in target. Package the app as a jar whose manifest names its Main-Class, then deploy again.'],
    'two runnable jars' => [['api/target/api-1.0.jar' => 'com.acme.Api', 'admin/target/admin-1.0.jar' => 'com.acme.Admin'], 'Coresmith found 2 runnable jars (admin/target/admin-1.0.jar api/target/api-1.0.jar) and runs exactly one. Build only the jar the app starts from, then deploy again.'],
    'two distributions' => [['api/build/install/api/bin/api' => "#!/bin/sh\n", 'admin/build/install/admin/bin/admin' => "#!/bin/sh\n"], 'Coresmith found 2 start scripts (admin/build/install/admin/bin/admin api/build/install/api/bin/api) and runs exactly one. Build only the app the server should run, then deploy again.'],
]);

test('the release is checked for the launcher before it goes live', function () {
    $paths = new ReleasePaths(javaDeployApp());

    expect(ApplicationType::Java->language()->releaseChecks(javaDeployApp(), $paths, '20261114120000'))->toBe([
        '.coresmith/app' => 'test -x ' . escapeshellarg($paths->appDirectory('20261114120000') . '/.coresmith/app'),
    ]);
});

test("the web unit runs the launcher with the app's Java on PATH, sizes the heap from the app and tells the framework its port", function () {
    $application = javaDeployApp();
    $builder = app(SystemdUnitBuilder::class);
    $web = $builder->build($application, 'web', (string) $builder->webCommand($application));

    expect($web)->toContain('ExecStart=' . (new ReleasePaths($application))->appDirectory((new ReleasePaths($application))->current()) . '/.coresmith/app')
        ->and($web)->toContain('Environment="JAVA_OPTS=-XX:MaxRAMPercentage=75"')
        ->and($web)->toContain('Environment="PORT=4500"')
        ->and($web)->toContain('Environment="SERVER_PORT=4500"')
        ->and($web)->toContain('Environment="SERVER_ADDRESS=127.0.0.1"')
        ->and($web)->toContain('Environment="PATH=' . JAVA_HOME_25 . '/bin:');
});

test('a failed build says where it stopped and what to do', function (string $output, string $explained) {
    expect(ApplicationType::Java->language()->explain(DeploymentPhases::BUILD, $output, javaDeployStack()))->toBe($explained);
})->with([
    'a Maven compile error' => ["[INFO] Compiling 12 source files\n[ERROR] COMPILATION ERROR : \n[INFO] -------------------------------------------------------------\n[ERROR] /var/coresmith/apps/shop/releases/20261114120000/src/main/java/com/acme/Shop.java:[12,8] cannot find symbol\n  symbol:   class Basket\n[INFO] 1 error\n", 'The Java build stopped at src/main/java/com/acme/Shop.java line 12: cannot find symbol. Fix it, then deploy again.'],
    'a Gradle compile error' => ["> Task :compileJava FAILED\n/var/coresmith/apps/shop/releases/20261114120000/web/src/main/java/com/acme/Shop.java:31: error: ';' expected\n        return basket\n                     ^\n1 error\n\nFAILURE: Build failed with an exception.\n\n* What went wrong:\nExecution failed for task ':compileJava'.\n> Compilation failed; see the compiler error output for details.\n", "The Java build stopped at web/src/main/java/com/acme/Shop.java line 31: ';' expected. Fix it, then deploy again."],
    'a release newer than the Java the app runs' => ["[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.13.0:compile (default-compile) on project shop: Fatal error compiling: error: release version 26 not supported -> [Help 1]\n", "The build targets Java 26, and the app runs Java 25. Set the app's Java to 26 or the build's release to 25, then deploy again."],
    "Gradle's toolchain asking for another Java" => ["* What went wrong:\nExecution failed for task ':compileJava'.\n> Error while evaluating property 'javaCompiler' of task ':compileJava'.\n   > Failed to calculate the value of task ':compileJava' property 'javaCompiler'.\n      > Cannot find a Java installation on your machine matching this tasks requirements: {languageVersion=21, vendor=any vendor, implementation=vendor-specific} for LINUX on aarch64.\n", "The build targets Java 21, and the app runs Java 25. Set the app's Java to 21 or the build's release to 25, then deploy again."],
    'a Maven goal that failed' => ["[ERROR] Failed to execute goal on project shop: Could not resolve dependencies for project com.acme:shop:jar:1.0: Could not find artifact com.acme:billing:jar:2.1 in central (https://repo.maven.apache.org/maven2) -> [Help 1]\n", 'The Maven build failed: Could not resolve dependencies for project com.acme:shop:jar:1.0: Could not find artifact com.acme:billing:jar:2.1 in central (https://repo.maven.apache.org/maven2). Fix it, then deploy again.'],
    'a Gradle build that failed' => ["FAILURE: Build failed with an exception.\n\n* What went wrong:\nExecution failed for task ':bootJar'.\n> Error while evaluating property 'mainClass' of task ':bootJar'.\n   > Failed to calculate the value of task ':bootJar' property 'mainClass'.\n      > Main class name has not been configured and it could not be resolved from classpath\n\n* Try:\n> Run with --stacktrace option to get the stack trace.\n", 'The Gradle build failed: Main class name has not been configured and it could not be resolved from classpath. Fix it, then deploy again.'],
    'a Gradle too old for the Java' => ["FAILURE: Build failed with an exception.\n\n* What went wrong:\nCould not open cp_settings generic class cache for settings file '/var/coresmith/apps/shop/releases/20261114120000/settings.gradle'.\n> BUG! exception in phase 'semantic analysis' in source unit '_BuildScript_' Unsupported class file major version 69\n", "The project's Gradle is too old to run on Java 25. Update its Gradle wrapper to a version that supports Java 25, commit it, then deploy again."],
    'no runnable jar' => ["[INFO] BUILD SUCCESS\nCoresmith found no runnable jar in target. Package the app as a jar whose manifest names its Main-Class, then deploy again.\n", 'Coresmith found no runnable jar in target. Package the app as a jar whose manifest names its Main-Class, then deploy again.'],
]);

test('only the build phase is explained', function () {
    expect(ApplicationType::Java->language()->explain(DeploymentPhases::DEPENDENCIES, "[ERROR] boom\n", javaDeployStack()))->toBeNull()
        ->and(ApplicationType::Java->language()->explain(DeploymentPhases::BUILD, "BUILD SUCCESSFUL\n", javaDeployStack()))->toBeNull();
});
