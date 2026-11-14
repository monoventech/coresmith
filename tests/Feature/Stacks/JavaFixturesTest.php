<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Detection\StackDetector;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use Illuminate\Support\Facades\Process;

function javaFixture(string $fixture): RepositoryFacts
{
    $directory = base_path('tests/Fixtures/repositories/' . $fixture);
    $output = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => $directory])->input(app(RepositoryProbe::class)->script())->run('bash -s')->output();

    return app(RepositoryProbe::class)->parse($output);
}

test('the probe reads a real Java project, and detection names its framework, its Java and how it builds', function (string $fixture, string $framework, string $version, array $tooling) {
    $facts = javaFixture($fixture);
    $detected = app(StackDetector::class)->detect($facts, fn(string $runtime): array => []);
    $application = new Application(['type' => ApplicationType::Java, 'framework' => $framework]);

    expect($detected->type)->toBe('java')
        ->and($detected->framework)->toBe($framework)
        ->and($detected->unsettled)->toBe([])
        ->and($detected->blockers)->toBe([])
        ->and($detected->version())->toBe($version)
        ->and(ApplicationType::Java->language()->tooling($application, $facts))->toBe($tooling);
})->with([
    'Spring Boot with Maven' => ['java-spring-maven', 'spring-boot', '21.0.13', ['build' => 'maven', 'wrapper' => true, 'production' => false, 'dropwizard' => false, 'war' => false, 'application' => false, 'shadow' => false]],
    'a JHipster monolith' => ['java-jhipster', 'jhipster', '21.0.13', ['build' => 'maven', 'wrapper' => true, 'production' => true, 'dropwizard' => false, 'war' => false, 'application' => false, 'shadow' => false]],
    'Micronaut with the Gradle Kotlin DSL' => ['java-micronaut-gradle', 'micronaut', '21.0.13', ['build' => 'gradle', 'wrapper' => true, 'production' => false, 'dropwizard' => false, 'war' => false, 'application' => true, 'shadow' => true]],
    'Javalin in a gradle init layout' => ['java-gradle-init', 'javalin', '17.0.21', ['build' => 'gradle', 'wrapper' => true, 'production' => false, 'dropwizard' => false, 'war' => false, 'application' => true, 'shadow' => true]],
    'Quarkus with a .java-version' => ['java-quarkus', 'quarkus', '25.0.5', ['build' => 'maven', 'wrapper' => true, 'production' => false, 'dropwizard' => false, 'war' => false, 'application' => false, 'shadow' => false]],
]);
