<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Moves\MoveColumns;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Stacks\Java;
use App\Stacks\JavaTooling;
use App\Stacks\Route;
use App\Stacks\StorageSeed;

function javaApp(array $attributes = [], array $tooling = []): Application
{
    $data = (new JavaTooling('maven', true, false, false))->toArray();

    return new Application(['type' => ApplicationType::Java, 'framework' => 'spring-boot', 'slug' => 'shop', 'java_version' => '25.0.5', 'base_port' => 4500, 'stack' => ['language_tooling' => [...$data, ...$tooling]], ...$attributes]);
}

test('a Java app runs its jar behind Caddy, with no PHP-FPM, no web root and no shared storage', function () {
    $language = ApplicationType::Java->language();

    expect($language)->toBeInstanceOf(Java::class)
        ->and(ApplicationType::Java->runtime())->toBe('java')
        ->and($language->usesFpm())->toBeFalse()
        ->and($language->route(javaApp()))->toBe(Route::ReverseProxy)
        ->and($language->route(javaApp())->servesFiles())->toBeFalse()
        ->and($language->storageSeed())->toBe(StorageSeed::None)
        ->and($language->initialEnvironment('spring-boot'))->toBe([])
        ->and($language->pathDirectories('/var/coresmith/apps/shop/current'))->toBe([]);
});

test('each framework is told its port and address in the variables it reads, and every Java app gets PORT', function (string $framework, array $environment) {
    expect(ApplicationType::Java->language()->listenEnvironment(javaApp(['framework' => $framework])))->toBe(['PORT' => '4500', 'HOST' => '127.0.0.1', ...$environment]);
})->with([
    'Spring Boot' => ['spring-boot', ['SERVER_PORT' => '4500', 'SERVER_ADDRESS' => '127.0.0.1']],
    'JHipster' => ['jhipster', ['SERVER_PORT' => '4500', 'SERVER_ADDRESS' => '127.0.0.1']],
    'Quarkus' => ['quarkus', ['QUARKUS_HTTP_PORT' => '4500', 'QUARKUS_HTTP_HOST' => '127.0.0.1']],
    'Micronaut' => ['micronaut', ['MICRONAUT_SERVER_PORT' => '4500', 'MICRONAUT_SERVER_HOST' => '127.0.0.1']],
    'Helidon' => ['helidon', ['SERVER_PORT' => '4500', 'SERVER_HOST' => '127.0.0.1']],
    'Javalin' => ['javalin', []],
    'Vert.x' => ['vertx', []],
    'plain Java' => ['plain', []],
]);

test("the web process runs the release's launcher, and Dropwizard is handed its config", function () {
    $paths = new ReleasePaths(javaApp());
    $live = $paths->appDirectory($paths->current());

    expect(ApplicationType::Java->language()->webCommand(javaApp()))->toBe("{$live}/.coresmith/app")
        ->and(ApplicationType::Java->language()->webCommand(javaApp(['framework' => 'dropwizard'], ['dropwizard' => true])))->toBe("{$live}/.coresmith/app server config.yml");
});

test("the heap follows the app's size through JAVA_OPTS, which the app's environment can change", function () {
    expect(ApplicationType::Java->language()->processEnvironment(javaApp()))->toBe(['JAVA_OPTS' => '-XX:MaxRAMPercentage=75']);
});

test('an app with no saved build or no Java version has no web command yet', function () {
    expect(ApplicationType::Java->language()->webCommand(javaApp(['stack' => []])))->toBeNull()
        ->and(ApplicationType::Java->language()->webCommand(javaApp(['java_version' => null])))->toBeNull();
});

test('the cron example runs a script, since running the app jar again would start a second web server', function () {
    expect(ApplicationType::Java->language()->cronExample(javaApp()))->toBe('sh scripts/nightly.sh');
});

test('a Java app keeps the Java version it was deployed with', function () {
    $stack = new Stack(['java' => new ResolvedRuntime('java', '21.0.13', 'pom.xml', '21', false)]);

    expect(javaApp()->runtimeVersions())->toBe(['java' => '25.0.5'])
        ->and($stack->applicationAttributes()['java_version'])->toBe('21.0.13');
});

test('the app page names Java, its line and its framework', function () {
    expect(ApplicationPresenter::runtime(javaApp()))->toBe('Java 25')
        ->and(ApplicationPresenter::kind(javaApp()))->toBe('Spring Boot')
        ->and(ApplicationPresenter::kind(javaApp(['framework' => 'jhipster'])))->toBe('JHipster')
        ->and(ApplicationPresenter::kind(javaApp(['framework' => 'plain'])))->toBe('Plain Java')
        ->and(ApplicationPresenter::kind(javaApp(['framework' => null])))->toBe('Java');
});

test('a Java line past its end of life says where to name a newer one', function (?string $source, string $fix) {
    $this->travelTo('2027-11-14');
    $application = javaApp(['java_version' => '17.0.21', 'stack' => $source === null ? [] : ['runtimes' => ['java' => ['source' => $source]]]]);

    expect(ApplicationPresenter::runtimeEol($application))->toBe(['runtime' => 'Java 17', 'ended' => '31 October 2027', 'fix' => $fix]);
})->with([
    'nothing named it' => [null, 'Add a .java-version containing 25 to the repository, then deploy.'],
    '.java-version' => ['.java-version', 'Set .java-version to 25, then deploy.'],
    '.sdkmanrc' => ['.sdkmanrc', 'Set java in .sdkmanrc to a 25 release, then deploy.'],
    '.tool-versions' => ['.tool-versions', 'Set java in .tool-versions to 25, then deploy.'],
    'system.properties' => ['system.properties', 'Set java.runtime.version in system.properties to 25, then deploy.'],
    'pom.xml' => ['pom.xml', 'Set the Java release in pom.xml to 25, then deploy.'],
    'build.gradle.kts' => ['build.gradle.kts', 'Set the Java toolchain in build.gradle.kts to 25, then deploy.'],
    "a module's build.gradle.kts" => ['app/build.gradle.kts', 'Set the Java toolchain in app/build.gradle.kts to 25, then deploy.'],
    "a module's pom.xml" => ['web/pom.xml', 'Set the Java release in web/pom.xml to 25, then deploy.'],
]);

test('moving an app to another team carries its Java version', function () {
    expect(MoveColumns::APPLICATIONS)->toContain('java_version');
});
