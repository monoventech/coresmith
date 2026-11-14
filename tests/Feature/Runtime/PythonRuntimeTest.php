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

function pythonRequirement(array $files, array &$notes = []): ?Requirement
{
    $detection = app(RuntimeDetector::class)->detect(new Application(['type' => ApplicationType::Python]), new RepositoryFacts($files, complete: true));
    $notes = $detection->notes;

    return $detection->requirements['python'] ?? null;
}

test('Python is a runtime Coresmith manages, with the upstream lines that still get fixes', function () {
    $catalog = app(Catalog::class);

    expect(AsdfManager::RUNTIMES)->toContain('python')
        ->and(Catalog::RUNTIMES)->toContain('python')
        ->and($catalog->label('python'))->toBe('Python')
        ->and($catalog->recommendedLine('python'))->toBe('3.14')
        ->and(array_column(config('coresmith.runtimes.python.versions'), 'latest'))->toBe(['3.15.2', '3.14.10', '3.13.18', '3.12.16', '3.11.18'])
        ->and(RuntimeBinaries::REQUIRED['python'])->toBe(['bin/python']);
});

test('uv is pinned to a release with a checksum for each server architecture', function () {
    expect(config('coresmith.uv.version'))->toBe('0.13.6')
        ->and(config('coresmith.uv.sha256'))->toBe([
            'x86_64' => 'f29c3e94ffabd7e6f3ba521dba169a9b7ec12100bf3257087f0f24f6123f3645',
            'aarch64' => '229e194255c7958d86d56e8790d969a6f74736592133f0dfd0076f754c642c1d',
        ]);
});

test('Python installs prebuilt through uv into the same folder layout as every other runtime', function () {
    $server = Server::factory()->create();
    $transport = (new FakeSSHTransport)->respondTo('test -d', "no\n")->respondTo("test -x '/opt/asdf-data/uv/0.13.6/uv'", '', 1);
    $connection = new SSHConnection($server, $transport);

    app(AsdfManager::class)->install($connection, $server, 'python', '3.14.10');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('https://github.com/astral-sh/uv/releases/download/$UV_VERSION/uv-$arch-unknown-linux-gnu.tar.gz')
        ->and($commands)->toContain('sha256sum -c')
        ->and($commands)->toContain("CORESMITH_UV_VERSION='0.13.6'")
        ->and($commands)->toContain('python install "$CORESMITH_PYTHON" --install-dir "$stage" --no-bin')
        ->and($commands)->toContain("CORESMITH_PYTHON='3.14.10'")
        ->and($commands)->toContain('ln -sfn python3')
        ->and($commands)->toContain('grep -rIl -- "$found" "$CORESMITH_PYTHON_DIR/$CORESMITH_PYTHON"')
        ->and($commands)->toContain('-m compileall -q -j 0')
        ->and($commands)->not->toContain('asdf install python')
        ->and($commands)->not->toContain('asdf plugin update');
});

test('pip version specifiers read like Composer constraints', function (string $specifier, string $best) {
    expect(VersionConstraint::parse($specifier, 'pip')?->best(['3.11.18', '3.12.16', '3.13.18', '3.14.10', '3.15.2']))->toBe($best);
})->with([
    'a floor' => ['>=3.11', '3.15.2'],
    'a range' => ['>=3.11,<3.14', '3.13.18'],
    'compatible release' => ['~=3.12', '3.15.2'],
    'compatible patch release' => ['~=3.12.1', '3.12.16'],
    'a wildcard' => ['==3.13.*', '3.13.18'],
]);

test('the Python version comes from .python-version, .tool-versions, requires-python or runtime.txt', function (array $files, string $constraint, string $source) {
    $requirement = pythonRequirement($files);

    expect($requirement?->constraint)->toBe($constraint)
        ->and($requirement?->source)->toBe($source);
})->with([
    '.python-version' => [['.python-version' => "3.13\n"], '3.13', '.python-version'],
    'runtime.txt' => [['runtime.txt' => "python-3.12.4\n"], '3.12.4', 'runtime.txt'],
    'requires-python' => [['pyproject.toml' => "[project]\nname = \"shop\"\nrequires-python = \">=3.11,<3.14\"\n"], '>=3.11,<3.14', 'pyproject.toml'],
    '.python-version before pyproject.toml' => [['.python-version' => "3.12\n", 'pyproject.toml' => "[project]\nrequires-python = \">=3.13\"\n"], '3.12', '.python-version'],
    'Poetry\'s python line' => [['pyproject.toml' => "[tool.poetry.dependencies]\npython = \">=3.8,<3.12\"\nfastapi = \"^0.104.0\"\n"], '>=3.8,<3.12', 'pyproject.toml'],
    'requires-python before Poetry\'s line' => [['pyproject.toml' => "[project]\nrequires-python = \">=3.13\"\n[tool.poetry.dependencies]\npython = \"^3.10\"\n"], '>=3.13', 'pyproject.toml'],
]);

test('Poetry\'s python line reads the way Poetry reads it', function (string $line, string $best) {
    $requirement = pythonRequirement(['pyproject.toml' => "[tool.poetry.dependencies]\npython = \"{$line}\"\n"]);

    expect(VersionConstraint::parse($requirement->constraint, $requirement->flavour)?->best(['3.11.18', '3.12.16', '3.13.18', '3.14.10', '3.15.2']))->toBe($best)
        ->and($requirement->field)->toBe('the python line in pyproject.toml');
})->with([
    'a range' => ['>=3.8,<3.12', '3.11.18'],
    'caret' => ['^3.12', '3.15.2'],
    'tilde' => ['~3.12', '3.12.16'],
]);

test('Python names the files it reads its version from', function () {
    expect(RuntimeDetector::SOURCES['python'])->toBe('.tool-versions, .python-version, pyproject.toml or runtime.txt');
});

test('Python is not an asdf plugin, so setting up a server does not add one', function () {
    expect(AsdfManager::PLUGINS)->not->toContain('python');
});

test('a partly installed Python is removed by deleting its folder as root, since asdf never managed it and root may have written to it', function () {
    $server = Server::factory()->create();
    $transport = new FakeSSHTransport;

    app(AsdfManager::class)->uninstall(new SSHConnection($server, $transport), 'python', '3.14.10');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toBe("rm -rf -- '/opt/asdf-data/installs/python/3.14.10'");
});

test('a server with Python but without the pinned uv is not ready for Python apps yet', function () {
    $server = Server::factory()->create();
    $ready = new FakeSSHTransport;
    $missing = (new FakeSSHTransport)->respondTo('/opt/asdf-data/uv/0.13.6/uv', '', 1);

    expect(app(RuntimeBinaries::class)->installed(new SSHConnection($server, $ready), 'python', '3.14.10'))->toBeTrue()
        ->and(app(RuntimeBinaries::class)->installed(new SSHConnection($server, $missing), 'python', '3.14.10'))->toBeFalse()
        ->and(implode("\n", $ready->commands()))->toContain("test -x '/opt/asdf-data/installs/python/3.14.10/bin/python'");
});

test('a missing uv is downloaded and checked against its checksum before Python apps use it', function () {
    $server = Server::factory()->create();
    $transport = (new FakeSSHTransport)->respondTo("test -x '/opt/asdf-data/uv/0.13.6/uv'", '', 1);

    $notes = app(RuntimeBinaries::class)->ensure(new SSHConnection($server, $transport), 'python', '3.14.10');

    expect(implode("\n", $transport->commands()))->toContain('sha256sum -c')
        ->and($notes)->toBe(['uv: installed 0.13.6, checksum verified']);
});

test('the probe reads the files Python names its version in', function () {
    expect(RepositoryProbe::FILES)->toContain('.python-version', 'runtime.txt', 'pyproject.toml');
});

test('pip version specifiers that rule out a whole line, or ask for an exact release, are read as pip reads them', function (string $specifier, ?string $best) {
    expect(VersionConstraint::parse($specifier, 'pip')?->best(['3.11.18', '3.12.16', '3.13.18', '3.14.10', '3.15.2']))->toBe($best);
})->with([
    'a line ruled out' => ['>=3.11,!=3.15.*', '3.14.10'],
    'a whole major ruled out' => ['>=3.11,!=4.*', '3.15.2'],
    'a ceiling without its patch' => ['<=3.12', '3.11.18'],
    'above a line without its patch' => ['>3.13,<3.14', '3.13.18'],
]);

test('an exact pip release is the version Coresmith installs, even when the catalog has a newer patch', function () {
    expect(VersionConstraint::parse('==3.12.4', 'pip')?->exact)->toBe('3.12.4')
        ->and(VersionConstraint::parse('==3.12', 'pip')?->exact)->toBe('3.12.0');
});

test('a .python-version naming a line picks the newest release of that line', function () {
    $requirement = pythonRequirement(['.python-version' => "3.12\n"]);

    expect(VersionConstraint::parse($requirement->constraint, $requirement->flavour)?->best(['3.12.16', '3.13.18']))->toBe('3.12.16');
});

test('.tool-versions names Python the way asdf does', function () {
    $requirement = pythonRequirement(['.tool-versions' => "python 3.13.2\n", '.python-version' => "3.12\n"]);

    expect($requirement->constraint)->toBe('3.13.2')
        ->and($requirement->source)->toBe('.tool-versions');
});

test('a .python-version that names something other than a CPython release is ignored with a note', function () {
    $notes = [];
    $requirement = pythonRequirement(['.python-version' => "pypy3.10\n"], $notes);

    expect($requirement)->toBeNull()
        ->and($notes)->toBe(['Ignored .python-version, because pypy3.10 is not a Python version Coresmith knows.']);
});

test('an open-ended Python constraint gets the recommended line, which has a year of wheels, unless it rules that line out', function (string $constraint, array $installed, string $version) {
    $requirement = new Requirement('python', $constraint, $constraint, 'pyproject.toml', 'requires-python in pyproject.toml', 'pip');

    expect(app(RuntimeResolver::class)->resolve('python', $requirement, null, $installed)->version)->toBe($version);
})->with([
    'a floor' => ['>=3.11', [], '3.14.10'],
    'a floor with an older 3.14 installed' => ['>=3.11', ['3.14.9', '3.15.2'], '3.14.9'],
    'only the newest line' => ['>=3.15', [], '3.15.2'],
    'a ceiling below the recommended line' => ['>=3.10,<3.14', [], '3.13.18'],
    'an exact release' => ['==3.15.1', [], '3.15.1'],
]);

test('other runtimes still take the newest version their constraint allows', function () {
    $requirement = new Requirement('nodejs', '>=20', '>=20', 'package.json', 'engines.node in package.json', 'npm');

    expect(app(RuntimeResolver::class)->resolve('nodejs', $requirement, null, [])->version)->toBe('26.9.0');
});
