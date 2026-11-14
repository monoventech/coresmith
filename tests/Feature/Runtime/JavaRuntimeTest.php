<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\InvalidRuntimeException;
use App\Models\Application;
use App\Models\Server;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\Requirement;
use App\Services\Runtime\RuntimeBinaries;
use App\Services\Runtime\RuntimeDetector;
use App\Services\Runtime\RuntimeResolver;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function javaRuntimeRequirement(array $files): ?Requirement
{
    return app(RuntimeDetector::class)->detect(new Application(['type' => ApplicationType::Java]), new RepositoryFacts($files, complete: true))->requirements['java'] ?? null;
}

test('Java is a runtime Coresmith manages, with the long-term lines Temurin still builds', function () {
    $catalog = app(Catalog::class);

    expect(AsdfManager::RUNTIMES)->toContain('java')
        ->and(AsdfManager::PLUGINS)->not->toContain('java')
        ->and(Catalog::RUNTIMES)->toContain('java')
        ->and($catalog->label('java'))->toBe('Java')
        ->and($catalog->recommendedLine('java'))->toBe('25')
        ->and(array_column(config('coresmith.runtimes.java.versions'), 'latest'))->toBe(['25.0.5', '21.0.13', '17.0.21'])
        ->and(config('coresmith.temurin_builds'))->toBe(['25.0.5' => '8', '21.0.13' => '9', '17.0.21' => '9'])
        ->and(RuntimeBinaries::REQUIRED['java'])->toBe(['bin/java']);
});

test('Java installs from the Temurin archive, checked against the checksum published beside it, into the same folder layout as every runtime', function () {
    $server = Server::factory()->create();
    $transport = (new FakeSSHTransport)->respondTo('test -d', "no\n");

    app(AsdfManager::class)->install(new SSHConnection($server, $transport), $server, 'java', '21.0.13');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('https://github.com/adoptium/temurin${major}-binaries/releases/download/jdk-${CORESMITH_JAVA}%2B${CORESMITH_JAVA_BUILD}/OpenJDK${major}U-jdk_${arch}_linux_hotspot_${CORESMITH_JAVA}_${CORESMITH_JAVA_BUILD}.tar.gz')
        ->and($commands)->toContain('.sha256.txt')
        ->and($commands)->toContain('sha256sum -c')
        ->and($commands)->toContain("CORESMITH_JAVA='21.0.13'")
        ->and($commands)->toContain("CORESMITH_JAVA_BUILD='9'")
        ->and($commands)->toContain('--strip-components=1')
        ->and($commands)->toContain("CORESMITH_JAVA_DIR='/opt/asdf-data/installs/java'");
});

test('a Java version Coresmith has no Temurin build number for is refused before anything downloads', function () {
    $server = Server::factory()->create();
    $transport = (new FakeSSHTransport)->respondTo('test -d', "no\n");

    expect(fn() => app(AsdfManager::class)->install(new SSHConnection($server, $transport), $server, 'java', '21.0.4'))->toThrow(InvalidRuntimeException::class)
        ->and(implode("\n", $transport->commands()))->not->toContain('curl');
});

test('the Java line comes from the version files Java projects keep, newest first in the order Coresmith reads them', function (array $files, string $constraint, string $source, string $raw) {
    $requirement = javaRuntimeRequirement($files);

    expect($requirement?->constraint)->toBe($constraint)
        ->and($requirement?->source)->toBe($source)
        ->and($requirement?->raw)->toBe($raw);
})->with([
    '.java-version' => [['.java-version' => "21.0.5\n"], '21', '.java-version', '21.0.5'],
    '.sdkmanrc' => [['.sdkmanrc' => "# sdkman\njava=17.0.12-tem\n"], '17', '.sdkmanrc', 'java=17.0.12-tem'],
    '.tool-versions' => [['.tool-versions' => "java temurin-25.0.1+8\n"], '25', '.tool-versions', 'temurin-25.0.1+8'],
    "Maven's release" => [['pom.xml' => '<project><properties><maven.compiler.release>21</maven.compiler.release></properties></project>'], '21', 'pom.xml', '<maven.compiler.release>21</maven.compiler.release>'],
    "Spring Boot's java.version" => [['pom.xml' => '<project><properties><java.version>17</java.version></properties></project>'], '17', 'pom.xml', '<java.version>17</java.version>'],
    "Gradle's toolchain" => [['build.gradle.kts' => "java {\n    toolchain {\n        languageVersion = JavaLanguageVersion.of(25)\n    }\n}\n"], '25', 'build.gradle.kts', 'JavaLanguageVersion.of(25)'],
    "Gradle's sourceCompatibility" => [['build.gradle' => "java {\n    sourceCompatibility = JavaVersion.VERSION_21\n}\n"], '21', 'build.gradle', 'sourceCompatibility = JavaVersion.VERSION_21'],
    "Groovy's quoted sourceCompatibility" => [['build.gradle' => "sourceCompatibility = '17'\n"], '17', 'build.gradle', "sourceCompatibility = '17'"],
    "Groovy's bare sourceCompatibility" => [['build.gradle' => "sourceCompatibility = 21\n"], '21', 'build.gradle', 'sourceCompatibility = 21'],
    "an old 1.8 sourceCompatibility" => [['build.gradle' => "sourceCompatibility = '1.8'\n"], '8', 'build.gradle', "sourceCompatibility = '1.8'"],
    "Kotlin's jvmToolchain" => [['build.gradle.kts' => "kotlin {\n    jvmToolchain(17)\n}\n"], '17', 'build.gradle.kts', 'jvmToolchain(17)'],
    "a module's Gradle toolchain" => [['settings.gradle.kts' => "include(\"app\")\n", 'app/build.gradle.kts' => "java {\n    toolchain {\n        languageVersion = JavaLanguageVersion.of(17)\n    }\n}\n"], '17', 'app/build.gradle.kts', 'JavaLanguageVersion.of(17)'],
    'the root build before a module' => [['pom.xml' => '<project><properties><maven.compiler.release>21</maven.compiler.release></properties></project>', 'web/pom.xml' => '<project><properties><maven.compiler.release>17</maven.compiler.release></properties></project>'], '21', 'pom.xml', '<maven.compiler.release>21</maven.compiler.release>'],
]);

test('a Java line picks the newest Temurin build of that line', function () {
    expect(app(RuntimeResolver::class)->resolve('java', javaRuntimeRequirement(['.java-version' => "21\n"]), null, [])->version)->toBe('21.0.13');
});

test('Java names the files it reads its version from, and the probe reads them', function () {
    expect(RuntimeDetector::SOURCES['java'])->toBe('.tool-versions, .java-version, .sdkmanrc, pom.xml or build.gradle')
        ->and(RepositoryProbe::FILES)->toContain('.java-version', '.sdkmanrc', 'pom.xml', 'build.gradle', 'build.gradle.kts', 'settings.gradle', 'settings.gradle.kts', '.yo-rc.json');
});
