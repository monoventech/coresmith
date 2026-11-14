<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
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
use App\Services\Runtime\VersionConstraint;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function goRuntimeRequirement(array $files, array &$notes = []): ?Requirement
{
    $detection = app(RuntimeDetector::class)->detect(new Application(['type' => ApplicationType::Go]), new RepositoryFacts($files, complete: true));
    $notes = $detection->notes;

    return $detection->requirements['go'] ?? null;
}

test('Go is a runtime Coresmith manages, with the two supported lines and the one before', function () {
    $catalog = app(Catalog::class);

    expect(AsdfManager::RUNTIMES)->toContain('go')
        ->and(Catalog::RUNTIMES)->toContain('go')
        ->and($catalog->label('go'))->toBe('Go')
        ->and($catalog->recommendedLine('go'))->toBe('1.27')
        ->and(array_column(config('coresmith.runtimes.go.versions'), 'latest'))->toBe(['1.27.4', '1.26.11', '1.25.14'])
        ->and(RuntimeBinaries::REQUIRED['go'])->toBe(['bin/go']);
});

test('Go installs from the official archive, checked against its published SHA-256, into the same folder layout as every other runtime', function () {
    $server = Server::factory()->create();
    $transport = (new FakeSSHTransport)->respondTo('test -d', "no\n");
    $connection = new SSHConnection($server, $transport);

    app(AsdfManager::class)->install($connection, $server, 'go', '1.27.4');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('https://dl.google.com/go/go${CORESMITH_GO}.linux-${arch}.tar.gz')
        ->and($commands)->toContain('.sha256')
        ->and($commands)->toContain('sha256sum -c')
        ->and($commands)->toContain("CORESMITH_GO='1.27.4'")
        ->and($commands)->toContain('--strip-components=1')
        ->and($commands)->toContain("CORESMITH_GO_DIR='/opt/asdf-data/installs/go'");
});

test('Go is not an asdf plugin, so setting up a server does not add one', function () {
    expect(AsdfManager::PLUGINS)->not->toContain('go')
        ->and(AsdfManager::PLUGINS)->not->toContain('golang');
});

test('a partly installed Go is removed by deleting its folder as root', function () {
    $server = Server::factory()->create();
    $transport = new FakeSSHTransport;

    app(AsdfManager::class)->uninstall(new SSHConnection($server, $transport), 'go', '1.26.11');

    expect(implode("\n", $transport->commands()))->toContain("rm -rf -- '/opt/asdf-data/installs/go/1.26.11'");
});

test('the Go version comes from .tool-versions, or as a floor from the go and toolchain lines in go.mod', function (array $files, string $constraint, string $source, string $field) {
    $requirement = goRuntimeRequirement($files);

    expect($requirement?->constraint)->toBe($constraint)
        ->and($requirement?->source)->toBe($source)
        ->and($requirement?->field)->toBe($field);
})->with([
    'the go line' => [['go.mod' => "module example.com/shop\n\ngo 1.23\n"], '>=1.23', 'go.mod', 'the go line in go.mod'],
    'the go line with a patch' => [['go.mod' => "module example.com/shop\n\ngo 1.26.2\n"], '>=1.26.2', 'go.mod', 'the go line in go.mod'],
    'a toolchain newer than the go line' => [['go.mod' => "module example.com/shop\n\ngo 1.25.0\n\ntoolchain go1.26.4\n"], '>=1.26.4', 'go.mod', 'the toolchain line in go.mod'],
    'a toolchain left to the default' => [['go.mod' => "module example.com/shop\n\ngo 1.25\n\ntoolchain default\n"], '>=1.25', 'go.mod', 'the go line in go.mod'],
    '.tool-versions' => [['.tool-versions' => "golang 1.26.9\n", 'go.mod' => "module example.com/shop\n\ngo 1.25\n"], '1.26.9', '.tool-versions', '.tool-versions'],
]);

test('a go line picks the newest Go the catalog has, since newer Go builds older code', function () {
    $requirement = goRuntimeRequirement(['go.mod' => "module example.com/shop\n\ngo 1.23\n"]);

    expect(app(RuntimeResolver::class)->resolve('go', $requirement, null, [])->version)->toBe('1.27.4')
        ->and(VersionConstraint::parse($requirement->constraint, $requirement->flavour)?->best(['1.25.14', '1.26.11']))->toBe('1.26.11');
});

test('Go names the files it reads its version from, and the probe reads them', function () {
    expect(RuntimeDetector::SOURCES['go'])->toBe('.tool-versions or go.mod')
        ->and(RepositoryProbe::FILES)->toContain('go.mod');
});
