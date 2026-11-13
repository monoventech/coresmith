<?php

declare(strict_types=1);

use App\Services\Updates\Version;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/ReleaseScriptHelpers.php';

afterEach(fn() => releaseScriptCleanUp());

test('the release script is valid bash', function () {
    $process = new Process(['/bin/bash', '-n', base_path('scripts/release.sh')]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(is_executable(base_path('scripts/release.sh')))->toBeTrue();
});

test('the release script passes shellcheck', function () {
    if ((new ExecutableFinder)->find('shellcheck') === null) {
        $this->markTestSkipped('shellcheck is not installed on this machine; CI installs it.');
    }

    $process = new Process(['shellcheck', '-S', 'style', base_path('scripts/release.sh')]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput());
});

test('without a version it prints how to use it', function () {
    $sandbox = releaseScriptSandbox();

    $process = releaseScriptRun($sandbox, []);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('./scripts/release.sh 1.0.2')
        ->and(releaseOutput($process))->toContain('./scripts/release.sh publish 1.0.2')
        ->and(releaseOutput($process))->toContain('--omit <commit>')
        ->and(releaseOutput($process))->toContain('Current version: 0.1.0')
        ->and(releaseOutput($process))->toContain('Latest tag: none');
});

test('a version that is not semver is refused', function (string $version) {
    $process = releaseScriptRun(releaseScriptSandbox(), [$version]);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("Version '{$version}' is not valid semver. Use 1.0.2 or 1.0.2-beta.1.");
})->with(['1.0', '1.0.0-beta..1', '1.0.0-01']);

test('a version too long for a tag every install reads is refused', function () {
    $version = '1.0.0-' . str_repeat('a', 58);
    $process = releaseScriptRun(releaseScriptSandbox(), [$version]);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("Version '{$version}' is longer than 63 characters, so installs would refuse its tag. Use a shorter one.");
});

test('the release script takes exactly the versions every install accepts', function () {
    preg_match('/^valid_version\(\) \{.*?^\}$/ms', releaseScriptBody(), $function);
    $versions = ['1.0.0', '1.0.2-beta.1', '10.20.30-rc.1.x-7', '1.0.0-0', '1.0.0-0a', '1.0.0-00a', '1.0.0-a.b.c', '1.0.0-alpha-1', '1.0.0--', '1.0.0-beta..1', '1.0.0-01', '1.0.0-0.01', '01.0.0', '1.00.0', '1.0', '1.0.0-', '1.0.0-.', '1.0.0-a.', '1.0.0+build', 'v1.0.0', '1.0.0 ', "1.0.0\nx", '1.0.0-' . str_repeat('a', 57), '1.0.0-' . str_repeat('a', 58)];
    $script = ($function[0] ?? '') . "\n" . 'for version in "$@"; do if valid_version "$version"; then printf 1; else printf 0; fi; done';
    $process = new Process(['/bin/bash', '-c', $script, 'bash', ...$versions]);
    $process->mustRun();

    expect(str_split($process->getOutput()))->toBe(array_map(fn(string $version): string => Version::tryParse("v{$version}") === null ? '0' : '1', $versions));
});

test('options that only fit preparing a stable release are refused elsewhere', function (array $arguments, string $message) {
    $process = releaseScriptRun(releaseScriptSandbox(), $arguments);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain($message);
})->with([
    'publishing a pre-release' => [['publish', '1.0.0-beta.1', '--yes'], 'A pre-release is published as it is cut. Run ./scripts/release.sh 1.0.0-beta.1.'],
    'omitting from a pre-release' => [['1.0.0-beta.1', '--omit', 'HEAD', '--yes'], '--omit and --skip-gates are for preparing a stable release.'],
    'skipping gates while publishing' => [['publish', '1.0.0', '--skip-gates', '--yes'], '--omit and --skip-gates are for preparing a stable release.'],
    'omitting nothing' => [['1.0.0', '--omit'], '--omit needs a commit.'],
]);

test('a dirty tree is refused', function () {
    $sandbox = releaseScriptSandbox();
    file_put_contents("{$sandbox['clone']}/VERSION", "9.9.9\n");

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('The working tree has changes. Commit them first, then release.');
});

test('gh must be signed in', function () {
    $process = releaseScriptRun(releaseScriptSandbox(), ['1.0.0', '--yes', '--unsigned'], ['STUB_FAIL_GH' => '1', 'STUB_FAIL_MATCH' => 'auth status']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('gh is not signed in. Run gh auth login, then release.');
});

test('a detached HEAD is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    releaseGit($sandbox, ['checkout', '--quiet', '--detach']);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('HEAD is detached. Check out a branch, then release.');
});

test('an existing tag is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseTag($sandbox, 'v1.0.0');

    $process = releaseScriptRun($sandbox, ['v1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('Tag v1.0.0 already exists.');
});

test('an existing release branch is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    releaseGit($sandbox, ['push', '--quiet', 'origin', 'main:refs/heads/release/v1.0.0']);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('release/v1.0.0 already exists. Merge or close its pull request and delete the branch, then release.');
});

test('local dev must match the remote before a release', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: unpushed', push: false);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('Local dev differs from origin/dev. Pull or push it, then release.');
});

test('a release with nothing new is refused', function () {
    $process = releaseScriptRun(releaseScriptSandbox(), ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('Nothing to release: everything on origin/dev is already on main.');
});

test('a no at the prompt changes nothing', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    $before = releaseRefs($sandbox);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--unsigned'], [], "n\n");

    expect($process->getExitCode())->toBe(0)
        ->and(releaseOutput($process))->toContain('Continue? [y/N]')
        ->and(releaseOutput($process))->toContain('Aborted.')
        ->and(releaseCalls($sandbox))->toBe(['gh auth status'])
        ->and(releaseRefs($sandbox))->toBe($before);
});

test('preparing a stable release picks dev onto main, bumps, runs the full ci on that exact commit and opens a pull request', function () {
    $sandbox = releaseScriptSandbox();
    releaseTag($sandbox, 'v0.1.0');
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    releaseCommit($sandbox, 'dev', 'fix: keep log colours');
    $main = releaseOrigin($sandbox, ['rev-parse', 'main']);
    $dev = releaseOrigin($sandbox, ['rev-parse', 'dev']);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process));

    $calls = array_values(array_filter(releaseCalls($sandbox), fn(string $line) => $line !== 'gh auth status'));
    $changelog = releaseOrigin($sandbox, ['show', 'release/v1.0.0:CHANGELOG.md']);
    $body = (string) file_get_contents($sandbox['body']);

    expect(array_slice($calls, 0, -1))->toBe([
        'bun run build',
        'bun run ci',
        'bun tests/Corpus/codeHash.ts',
    ])->and(end($calls))->toMatch('#^gh pr create --repo monoven/coresmith --base main --head release/v1\.0\.0 --title Release v1\.0\.0 --body-file \S+$#')
        ->and((string) file_get_contents($sandbox['ci']))->toBe(releaseOrigin($sandbox, ['rev-parse', 'release/v1.0.0']) . " 1.0.0 0\n")
        ->and(releaseOrigin($sandbox, ['rev-parse', 'main']))->toBe($main)
        ->and(releaseOrigin($sandbox, ['rev-parse', 'dev']))->toBe($dev)
        ->and(releaseOrigin($sandbox, ['tag', '--list']))->toBe('v0.1.0')
        ->and(releaseGitProcess($sandbox, ['merge-base', '--is-ancestor', 'main', 'release/v1.0.0'], $sandbox['origin'])->isSuccessful())->toBeTrue()
        ->and(explode("\n", releaseOrigin($sandbox, ['log', '--format=%s', 'main..release/v1.0.0'])))->toBe(['chore(release): v1.0.0', 'fix: keep log colours', 'feat: deploy faster'])
        ->and(releaseOrigin($sandbox, ['log', '-1', '--format=%b', 'release/v1.0.0~1']))->toContain("(cherry picked from commit {$dev})")
        ->and(releaseOrigin($sandbox, ['show', 'release/v1.0.0:VERSION']))->toBe('1.0.0')
        ->and(releaseOrigin($sandbox, ['show', 'release/v1.0.0:scripts/install.sh']))->toContain("\nreadonly CORESMITH_VERSION=\"1.0.0\"\n")
        ->and(releaseOrigin($sandbox, ['ls-tree', 'release/v1.0.0', 'scripts/install.sh']))->toStartWith('100755 ')
        ->and($changelog)->toMatch('/^# Changelog\n\n## v1\.0\.0 \(\d{4}-\d{2}-\d{2}\)\n\n### Added/')
        ->and($changelog)->toContain("### Added\n- feat: deploy faster")
        ->and($changelog)->toContain("### Fixed\n- fix: keep log colours")
        ->and($changelog)->not->toContain('chore')
        ->and($changelog)->not->toContain('—')
        ->and($body)->toStartWith("### Added\n- feat: deploy faster\n\n### Fixed\n- fix: keep log colours\n")
        ->and($body)->toContain('Merge it with any method')
        ->and($body)->toContain('./scripts/release.sh publish 1.0.0')
        ->and($body)->not->toContain('—')
        ->and(releaseOutput($process))->toContain('Picked 2 commits from dev.')
        ->and(releaseOutput($process))->toContain('Opened https://github.com/monoven/coresmith/pull/1')
        ->and(releaseOutput($process))->not->toContain('—')
        ->and(releaseGitProcess($sandbox, ['rev-parse', '-q', '--verify', 'refs/heads/release/v1.0.0'])->isSuccessful())->toBeFalse()
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('')
        ->and(releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('main');
});

test('publishing tags the merge commit and creates the GitHub release', function () {
    $sandbox = releaseScriptSandbox();
    releaseTag($sandbox, 'v0.1.0');
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    releaseCommit($sandbox, 'dev', 'fix: keep log colours');

    expect(releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    $merge = releaseMergePr($sandbox, '1.0.0');
    file_put_contents($sandbox['log'], '');

    $process = releaseScriptRun($sandbox, ['publish', '1.0.0', '--yes', '--unsigned']);
    $calls = releaseCalls($sandbox);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and($calls)->toHaveCount(3)
        ->and($calls[1])->toStartWith('gh pr list --repo monoven/coresmith --head release/v1.0.0 --state all ')
        ->and(end($calls))->toMatch('#^gh release create v1\.0\.0 --repo monoven/coresmith --title v1\.0\.0 --notes-file \S+ --verify-tag --latest \S+/install\.sh$#')
        ->and(releaseOrigin($sandbox, ['rev-parse', 'v1.0.0^{commit}']))->toBe($merge)
        ->and(releaseOrigin($sandbox, ['rev-parse', 'main']))->toBe($merge)
        ->and(releaseOrigin($sandbox, ['cat-file', '-t', 'v1.0.0']))->toBe('tag')
        ->and(file_get_contents($sandbox['notes']))->toBe("### Added\n- feat: deploy faster\n\n### Fixed\n- fix: keep log colours\n")
        ->and(releaseGit($sandbox, ['hash-object', $sandbox['installer']]))->toBe(releaseOrigin($sandbox, ['rev-parse', 'v1.0.0:scripts/install.sh']))
        ->and(releaseOutput($process))->toContain('Published https://github.com/monoven/coresmith/releases/tag/v1.0.0')
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('');
});

test('the first stable release says so in the changelog', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseOrigin($sandbox, ['show', 'release/v1.0.0:CHANGELOG.md']))->toMatch('/^# Changelog\n\n## v1\.0\.0 \(\d{4}-\d{2}-\d{2}\)\n\nInitial release\.$/')
        ->and(file_get_contents($sandbox['body']))->toStartWith("Initial release.\n");
});

test('the next release offers only what main lacks, and a pre-release never lands on dev or main', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: one');

    expect(releaseShip($sandbox, '1.0.0')->getExitCode())->toBe(0);

    releaseCommit($sandbox, 'dev', 'fix: two');
    $dev = releaseOrigin($sandbox, ['rev-parse', 'dev']);
    $main = releaseOrigin($sandbox, ['rev-parse', 'main']);

    $beta = releaseScriptRun($sandbox, ['1.0.1-beta.1', '--yes']);

    expect($beta->getExitCode())->toBe(0, releaseOutput($beta))
        ->and(releaseOrigin($sandbox, ['rev-parse', 'dev']))->toBe($dev)
        ->and(releaseOrigin($sandbox, ['rev-parse', 'main']))->toBe($main)
        ->and(releaseOrigin($sandbox, ['rev-parse', 'v1.0.1-beta.1^{commit}^']))->toBe($dev);

    releaseCommit($sandbox, 'dev', 'feat: three');

    $stable = releaseScriptRun($sandbox, ['1.0.1', '--yes', '--unsigned']);

    expect($stable->getExitCode())->toBe(0, releaseOutput($stable));

    $changelog = releaseOrigin($sandbox, ['show', 'release/v1.0.1:CHANGELOG.md']);
    $entry = substr($changelog, strpos($changelog, '## v1.0.1 ('), strpos($changelog, '## v1.0.0 (') - strpos($changelog, '## v1.0.1 ('));

    expect(explode("\n", releaseOrigin($sandbox, ['log', '--format=%s', 'main..release/v1.0.1'])))->toBe(['chore(release): v1.0.1', 'feat: three', 'fix: two'])
        ->and($changelog)->toStartWith("# Changelog\n\n## v1.0.1 (")
        ->and($entry)->toContain("### Added\n- feat: three")
        ->and($entry)->toContain("### Fixed\n- fix: two")
        ->and($entry)->not->toContain('feat: one')
        ->and($entry)->not->toContain('chore(release)')
        ->and($changelog)->toContain('Initial release.')
        ->and(releaseOrigin($sandbox, ['show', 'release/v1.0.1:VERSION']))->toBe('1.0.1')
        ->and(releaseOrigin($sandbox, ['show', 'release/v1.0.1:scripts/install.sh']))->toContain("\nreadonly CORESMITH_VERSION=\"1.0.1\"\n");
});

test('a release leaves out the commits it is told to, and the next one offers them again', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: ready');
    releaseCommit($sandbox, 'dev', 'feat: not ready yet');
    $later = releaseGit($sandbox, ['rev-parse', 'origin/dev']);
    $short = releaseGit($sandbox, ['log', '-1', '--format=%h %s', $later]);
    releaseCommit($sandbox, 'dev', 'fix: also ready');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--omit', substr($later, 0, 9), '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(explode("\n", releaseOrigin($sandbox, ['log', '--format=%s', 'main..release/v1.0.0'])))->toBe(['chore(release): v1.0.0', 'fix: also ready', 'feat: ready'])
        ->and(file_get_contents($sandbox['body']))->toContain("### Left out for a later release\n- {$short}\n")
        ->and(releaseOutput($process))->toContain('Picked 2 commits from dev and left out 1.');

    releaseMergePr($sandbox, '1.0.0');

    expect(releaseScriptRun($sandbox, ['publish', '1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    $next = releaseScriptRun($sandbox, ['1.0.1', '--yes', '--unsigned']);

    expect($next->getExitCode())->toBe(0, releaseOutput($next))
        ->and(explode("\n", releaseOrigin($sandbox, ['log', '--format=%s', 'main..release/v1.0.1'])))->toBe(['chore(release): v1.0.1', 'feat: not ready yet'])
        ->and(file_get_contents($sandbox['body']))->not->toContain('Left out');
});

test('an omitted commit must be one dev would release', function (string $omit) {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--omit', $omit, '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("{$omit} isn't one of the commits origin/dev would release.");
})->with(['nothere', 'main']);

test('leaving out everything is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: not ready yet');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--omit', 'origin/dev', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('Nothing is left to release once those commits are left out.');
});

test('a commit that builds on a left-out one stops the release and says so', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: start the page', 'page.txt', "one\n");
    $base = releaseGit($sandbox, ['rev-parse', 'origin/dev']);
    releaseCommit($sandbox, 'dev', 'feat: finish the page', 'page.txt', "two\n");
    $before = releaseRefs($sandbox);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--omit', $base, '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain(releaseGit($sandbox, ['log', '-1', '--format=%h %s', 'origin/dev']) . " doesn't apply on main without the commits left out of this release. Leave it out too, or keep what it builds on.")
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(releaseRefs($sandbox))->toBe($before)
        ->and(releaseGitProcess($sandbox, ['rev-parse', '-q', '--verify', 'refs/heads/release/v1.0.0'])->isSuccessful())->toBeFalse()
        ->and(releaseGitProcess($sandbox, ['rev-parse', '-q', '--verify', 'CHERRY_PICK_HEAD'])->isSuccessful())->toBeFalse()
        ->and(releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('main')
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('');
});

test('a commit that conflicts with main stops the release', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'main', 'docs: main readme', 'README.md', "main\n");
    releaseCommit($sandbox, 'dev', 'docs: dev readme', 'README.md', "dev\n");
    $before = releaseRefs($sandbox);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain(releaseGit($sandbox, ['log', '-1', '--format=%h %s', 'origin/dev']) . " doesn't apply on main. Merge main into dev with a pull request, then release.")
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(releaseRefs($sandbox))->toBe($before)
        ->and(releaseGitProcess($sandbox, ['rev-parse', '-q', '--verify', 'CHERRY_PICK_HEAD'])->isSuccessful())->toBeFalse()
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('');
});

test('a commit that only clashes with the version line takes dev\'s side and is bumped', function () {
    $sandbox = releaseScriptSandbox();
    $installer = (string) file_get_contents("{$sandbox['clone']}/scripts/install.sh");
    releaseCommit($sandbox, 'main', 'chore(release): v9.9.9', 'scripts/install.sh', (string) preg_replace('/^readonly CORESMITH_VERSION=".*"$/m', 'readonly CORESMITH_VERSION="9.9.9"', $installer));
    releaseCommit($sandbox, 'dev', 'feat: install from the next repository', 'scripts/install.sh', str_replace('monoventech/coresmith.git}"', 'monoventech/coresmith-next.git}"', $installer));

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);
    $released = releaseOrigin($sandbox, ['show', 'release/v1.0.0:scripts/install.sh']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and($released)->toContain("\nreadonly CORESMITH_VERSION=\"1.0.0\"\n")
        ->and($released)->toContain('monoventech/coresmith-next.git}"')
        ->and(releaseOrigin($sandbox, ['ls-tree', 'release/v1.0.0', 'scripts/install.sh']))->toStartWith('100755 ');
});

test('a failing gate leaves nothing behind', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    $before = releaseRefs($sandbox);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'], ['STUB_FAIL_BUN' => '1', 'STUB_FAIL_MATCH' => 'run ci']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('bun run ci failed. Fix it on dev, then release.')
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(array_filter(releaseCalls($sandbox), fn(string $line) => str_starts_with($line, 'gh pr create')))->toBe([])
        ->and(releaseGitProcess($sandbox, ['rev-parse', '-q', '--verify', 'refs/heads/release/v1.0.0'])->isSuccessful())->toBeFalse()
        ->and(releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('main')
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('')
        ->and(releaseRefs($sandbox))->toBe($before);
});

test('a release stops until the fixed set of real repositories has passed on its code', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    unlink("{$sandbox['clone']}/storage/corpus/fixed-pass-stubbedhash.json");

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("The fixed set of real repositories hasn't passed on this detection and deploy code (stubbedhash).")
        ->and(array_filter(releaseCalls($sandbox), fn(string $line) => str_starts_with($line, 'gh pr create')))->toBe([]);
});

test('a rejected push leaves nothing behind', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    file_put_contents("{$sandbox['origin']}/hooks/update", "#!/bin/sh\ncase \"\$1\" in refs/heads/release/*) exit 1 ;; esac\nexit 0\n");
    chmod("{$sandbox['origin']}/hooks/update", 0755);
    $before = releaseRefs($sandbox);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('origin refused the push.')
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(releaseRefs($sandbox))->toBe($before)
        ->and(array_filter(releaseCalls($sandbox), fn(string $line) => str_starts_with($line, 'gh pr create')))->toBe([])
        ->and(releaseGitProcess($sandbox, ['rev-parse', '-q', '--verify', 'refs/heads/release/v1.0.0'])->isSuccessful())->toBeFalse()
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('');
});

test('a pull request that is not opened keeps the pushed branch and says how to finish', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'], ['STUB_FAIL_GH' => '1', 'STUB_FAIL_MATCH' => 'pr create']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("release/v1.0.0 is pushed, but the pull request wasn't opened. Open it with:")
        ->and(releaseOutput($process))->toContain('  gh pr create --repo monoven/coresmith --base main --head release/v1.0.0 --title Release\ v1.0.0 --body-file ')
        ->and(releaseOutput($process))->not->toContain('Nothing was pushed')
        ->and(releaseOrigin($sandbox, ['log', '-1', '--format=%s', 'release/v1.0.0']))->toBe('chore(release): v1.0.0')
        ->and(releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('main');

    preg_match('#--body-file (\S+)#', releaseOutput($process), $body);

    expect(is_file($body[1]))->toBeTrue();

    File::deleteDirectory(dirname($body[1]));
});

test('publishing needs the release pull request merged', function (?string $state, string $message) {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    expect(releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    if ($state !== null) {
        releasePrState($sandbox, $state);
    }

    $process = releaseScriptRun($sandbox, ['publish', '1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain($message)
        ->and(releaseOrigin($sandbox, ['tag', '--list']))->toBe('');
})->with([
    'no pull request' => [null, "There's no pull request for release/v1.0.0. Open one with ./scripts/release.sh 1.0.0."],
    'still open' => ['OPEN', "https://github.com/monoven/coresmith/pull/1 isn't merged yet. Merge it, then publish."],
    'closed' => ['CLOSED', 'https://github.com/monoven/coresmith/pull/1 was closed without merging. Open a new one with ./scripts/release.sh 1.0.0.'],
]);

test('a squashed release pull request publishes, and the next release knows what it shipped', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: one', 'page.txt', "one\n");
    releaseCommit($sandbox, 'dev', 'fix: two', 'page.txt', "two\n");

    expect(releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    $merge = releaseMergePr($sandbox, '1.0.0', squash: true);
    $published = releaseScriptRun($sandbox, ['publish', '1.0.0', '--yes', '--unsigned']);

    expect($published->getExitCode())->toBe(0, releaseOutput($published))
        ->and(releaseOrigin($sandbox, ['rev-parse', 'v1.0.0^{commit}']))->toBe($merge)
        ->and(releaseOrigin($sandbox, ['rev-list', '--parents', '-n', '1', 'main']))->toBe("{$merge} " . releaseOrigin($sandbox, ['rev-parse', 'main^']));

    releaseCommit($sandbox, 'dev', 'feat: three');

    $next = releaseScriptRun($sandbox, ['1.0.1', '--yes', '--unsigned']);

    expect($next->getExitCode())->toBe(0, releaseOutput($next))
        ->and(explode("\n", releaseOrigin($sandbox, ['log', '--format=%s', 'main..release/v1.0.1'])))->toBe(['chore(release): v1.0.1', 'feat: three'])
        ->and(releaseOutput($next))->toContain('Picked 1 commit from dev.');
});

test('a release with everything already shipped through a squash is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: one');

    expect(releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    releaseMergePr($sandbox, '1.0.0', squash: true);

    $process = releaseScriptRun($sandbox, ['1.0.1', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('Nothing to release: everything on origin/dev is already on main.');
});

test('publishing checks main has the version being published', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    expect(releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    releaseMergePr($sandbox, '1.0.0');

    $process = releaseScriptRun($sandbox, ['publish', '1.0.1', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('https://github.com/monoven/coresmith/pull/1 put VERSION 1.0.0 on main, not 1.0.1.');
});

test('a rejected tag push leaves no tag behind', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    expect(releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    releaseMergePr($sandbox, '1.0.0');
    file_put_contents("{$sandbox['origin']}/hooks/update", "#!/bin/sh\ncase \"\$1\" in refs/tags/*) exit 1 ;; esac\nexit 0\n");
    chmod("{$sandbox['origin']}/hooks/update", 0755);

    $process = releaseScriptRun($sandbox, ['publish', '1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('origin refused the push.')
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(releaseOrigin($sandbox, ['tag', '--list']))->toBe('')
        ->and(releaseGit($sandbox, ['tag', '--list']))->toBe('');
});

test('a failed github release keeps the pushed tag and says how to finish', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    expect(releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    releaseMergePr($sandbox, '1.0.0');

    $process = releaseScriptRun($sandbox, ['publish', '1.0.0', '--yes', '--unsigned'], ['STUB_FAIL_GH' => '1', 'STUB_FAIL_MATCH' => 'release create']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("v1.0.0 is pushed, but the GitHub release wasn't created. Finish it with:")
        ->and(releaseOutput($process))->toMatch('#  gh release create v1\.0\.0 --repo monoven/coresmith --title v1\.0\.0 --notes-file (\S+) --verify-tag --latest \S+/install\.sh#')
        ->and(releaseOutput($process))->not->toContain('Nothing was pushed')
        ->and(releaseOrigin($sandbox, ['tag', '--list', 'v1.0.0']))->toBe('v1.0.0');

    preg_match('#--notes-file (\S+)#', releaseOutput($process), $notes);

    expect(is_file($notes[1]))->toBeTrue();

    File::deleteDirectory(dirname($notes[1]));
});

test('a build that changes other tracked files is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    $before = releaseRefs($sandbox);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'], ['STUB_BUILD_EDITS' => 'VERSION']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('The build changed tracked files: VERSION. Commit them on dev first.')
        ->and(releaseRefs($sandbox))->toBe($before)
        ->and(releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('main')
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('');
});

test('a build rewrites nothing, so nothing is put back afterwards', function () {
    $package = json_decode((string) file_get_contents(base_path('package.json')), true);

    expect($package['scripts']['build'])->toBe('vite build')
        ->and($package['scripts']['dev'])->toBe('vite')
        ->and($package['scripts'])->not->toHaveKey('generate')
        ->and(releaseScriptBody())->not->toContain('service-worker')
        ->and((string) file_get_contents(base_path('scripts/ci-local.sh')))->not->toContain('service-worker');

    foreach (['service-worker.js', 'manifest.json', 'offline.html', 'scripts', 'images/icons'] as $leftover) {
        expect(file_exists(public_path($leftover)))->toBeFalse("public/{$leftover} is back");
    }
});

test('--skip-gates skips the gates when the release is exactly dev', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: one');

    expect(releaseShip($sandbox, '1.0.0')->getExitCode())->toBe(0);

    releaseCommit($sandbox, 'dev', 'feat: two');
    file_put_contents($sandbox['log'], '');

    $process = releaseScriptRun($sandbox, ['1.0.1', '--skip-gates', '--yes', '--unsigned']);
    $calls = array_values(array_filter(releaseCalls($sandbox), fn(string $line) => $line !== 'gh auth status'));

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseOutput($process))->toContain("Skipping the gates: v1.0.1 is exactly origin/dev, so dev's bun run ci covers it.")
        ->and($calls[0])->toBe('bun run build')
        ->and(end($calls))->toStartWith('gh pr create ');
});

test('--skip-gates is refused when the release is not exactly dev', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: ready');
    releaseCommit($sandbox, 'dev', 'feat: not ready yet');
    $before = releaseRefs($sandbox);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--omit', 'origin/dev', '--skip-gates', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("--skip-gates needs the release to be exactly origin/dev, and this one isn't. Run the gates.")
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(releaseRefs($sandbox))->toBe($before);
});

test('a pre-release is tagged on top of dev without touching dev or main', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    $dev = releaseOrigin($sandbox, ['rev-parse', 'dev']);
    $main = releaseOrigin($sandbox, ['rev-parse', 'main']);

    $process = releaseScriptRun($sandbox, ['1.0.0-beta.1'], [], "y\n");

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseOutput($process))->toContain('Continue? [y/N]')
        ->and(releaseOutput($process))->toContain('No signing key is configured, so v1.0.0-beta.1 is tagged without a signature.')
        ->and(releaseOrigin($sandbox, ['rev-parse', 'dev']))->toBe($dev)
        ->and(releaseOrigin($sandbox, ['rev-parse', 'main']))->toBe($main)
        ->and(releaseOrigin($sandbox, ['rev-parse', 'v1.0.0-beta.1^{commit}^']))->toBe($dev)
        ->and(releaseOrigin($sandbox, ['show', 'v1.0.0-beta.1:VERSION']))->toBe('1.0.0-beta.1')
        ->and(releaseOrigin($sandbox, ['log', '-1', '--format=%s', 'v1.0.0-beta.1']))->toBe('chore(release): v1.0.0-beta.1')
        ->and(releaseOrigin($sandbox, ['branch', '--list', 'release/*']))->toBe('')
        ->and(releaseLastCall($sandbox))->toMatch('#^gh release create v1\.0\.0-beta\.1 --repo monoven/coresmith --title v1\.0\.0-beta\.1 --notes-file \S+ --verify-tag --prerelease \S+/install\.sh$#')
        ->and(file_get_contents($sandbox['notes']))->toBe("Pre-release v1.0.0-beta.1.\n")
        ->and(releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('main')
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('');
});

test('a stable release without a signing key needs --unsigned', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $refused = releaseScriptRun($sandbox, ['1.0.0', '--yes']);

    expect($refused->getExitCode())->toBe(1)
        ->and(releaseOutput($refused))->toContain('No signing key is configured. Set one with git config user.signingkey <id>, or pass --unsigned to release v1.0.0 without a signature. Updaters will report it as unchecked.')
        ->and(releaseOrigin($sandbox, ['branch', '--list', 'release/*']))->toBe('');

    $published = releaseShip($sandbox, '1.0.0');

    expect($published->getExitCode())->toBe(0, releaseOutput($published))
        ->and(releaseOutput($published))->toContain('v1.0.0 will be unsigned. Updaters will report it as unchecked.')
        ->and(releaseOrigin($sandbox, ['tag', '--list', 'v1.0.0']))->toBe('v1.0.0');
});

test('an SSH signing key counts as no key', function () {
    $sandbox = releaseScriptSandbox();
    releaseGit($sandbox, ['config', 'gpg.format', 'ssh']);
    releaseGit($sandbox, ['config', 'user.signingkey', "{$sandbox['base']}/id_ed25519.pub"]);
    releaseGit($sandbox, ['config', 'tag.gpgSign', 'true']);
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $refused = releaseScriptRun($sandbox, ['1.0.0', '--yes']);

    expect($refused->getExitCode())->toBe(1)
        ->and(releaseOutput($refused))->toContain('No signing key is configured.');

    $published = releaseShip($sandbox, '1.0.0');

    expect($published->getExitCode())->toBe(0, releaseOutput($published))
        ->and(releaseOrigin($sandbox, ['cat-file', '-p', 'v1.0.0']))->not->toContain('SIGNATURE');
});

test('the GitHub repository is read from the remote address', function (string $url) {
    $sandbox = releaseScriptSandbox();
    file_put_contents($sandbox['gitconfig'], "[url \"{$sandbox['origin']}\"]\n\tinsteadOf = {$url}\n");
    releaseGit($sandbox, ['remote', 'set-url', 'origin', $url]);

    $process = releaseScriptRun($sandbox, ['1.0.0-beta.1', '--yes'], ['CORESMITH_RELEASE_REPO' => false]);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseLastCall($sandbox))->toContain('--repo monoven/coresmith ');
})->with([
    'ssh' => 'git@github.com:monoven/coresmith.git',
    'https' => 'https://github.com/monoven/coresmith',
    'https with .git' => 'https://github.com/monoven/coresmith.git',
]);

test('a remote that is not on GitHub needs the repository named', function () {
    $sandbox = releaseScriptSandbox();

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'], ['CORESMITH_RELEASE_REPO' => false]);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("Set CORESMITH_RELEASE_REPO to owner/name: origin doesn't point at GitHub.");
});

test('with a signing key the tag is signed and the installer signature is attached', function () {
    if (!releaseHasGpg()) {
        $this->markTestSkipped('gpg is not installed on this machine; CI installs it.');
    }

    $sandbox = releaseScriptSandbox();
    $key = releaseGpgKey($sandbox, 'Release Key');
    releaseGit($sandbox, ['config', 'user.signingkey', $key]);
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseShip($sandbox, '1.0.0', ['--yes'], ['--yes']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseGitProcess($sandbox, ['verify-tag', 'v1.0.0'], $sandbox['origin'])->isSuccessful())->toBeTrue()
        ->and(releaseLastCall($sandbox))->toMatch('# \S+/install\.sh \S+/install\.sh\.asc$#')
        ->and(releaseGpg($sandbox, ['--verify', $sandbox['asset'], $sandbox['installer']])->isSuccessful())->toBeTrue()
        ->and(releaseGit($sandbox, ['hash-object', $sandbox['installer']]))->toBe(releaseOrigin($sandbox, ['rev-parse', 'v1.0.0:scripts/install.sh']));
});

test('a signing key is checked against the release keys the panel ships', function () {
    if (!releaseHasGpg()) {
        $this->markTestSkipped('gpg is not installed on this machine; CI installs it.');
    }

    $sandbox = releaseScriptSandbox();
    $signing = releaseGpgKey($sandbox, 'Release Key');
    releaseShipKey($sandbox, 'dev', releaseGpgKey($sandbox, 'Other Key'), 'other');
    releaseGit($sandbox, ['config', 'user.signingkey', $signing]);

    $refused = releaseScriptRun($sandbox, ['1.0.0', '--yes']);

    expect($refused->getExitCode())->toBe(1)
        ->and(releaseOutput($refused))->toContain("Your signing key isn't one of resources/release-keys, so every updater would reject v1.0.0.");

    releaseShipKey($sandbox, 'dev', $signing, 'release');

    $published = releaseShip($sandbox, '1.0.0', ['--yes'], ['--yes']);

    expect($published->getExitCode())->toBe(0, releaseOutput($published))
        ->and(releaseGitProcess($sandbox, ['verify-tag', 'v1.0.0'], $sandbox['origin'])->isSuccessful())->toBeTrue();
});

test('a release that ships keys must be signed', function (array $arguments, bool $configured) {
    if (!releaseHasGpg()) {
        $this->markTestSkipped('gpg is not installed on this machine; CI installs it.');
    }

    $sandbox = releaseScriptSandbox();
    $key = releaseGpgKey($sandbox, 'Release Key');
    releaseShipKey($sandbox, 'dev', $key, 'release');

    if ($configured) {
        releaseGit($sandbox, ['config', 'user.signingkey', $key]);
    }

    $process = releaseScriptRun($sandbox, $arguments);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('This release ships release keys, so every updater requires a signature. Configure the signing key, then release.');
})->with([
    'no key' => [['1.0.0', '--yes'], false],
    '--unsigned with a key' => [['1.0.0', '--yes', '--unsigned'], true],
    'a pre-release with no key' => [['1.0.0-rc.1', '--yes'], false],
]);

test('--unsigned wins over a configured key while no release key ships', function () {
    if (!releaseHasGpg()) {
        $this->markTestSkipped('gpg is not installed on this machine; CI installs it.');
    }

    $sandbox = releaseScriptSandbox();
    releaseGit($sandbox, ['config', 'user.signingkey', releaseGpgKey($sandbox, 'Release Key')]);
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseShip($sandbox, '1.0.0');

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseOutput($process))->toContain('v1.0.0 will be unsigned.')
        ->and(releaseOrigin($sandbox, ['cat-file', '-p', 'v1.0.0']))->not->toContain('BEGIN PGP SIGNATURE')
        ->and(releaseLastCall($sandbox))->toEndWith('/install.sh')
        ->and(is_file($sandbox['asset']))->toBeFalse();
});

test('the release gate is the full ci every pull request passes', function () {
    $ci = (string) file_get_contents(base_path('scripts/ci-local.sh'));

    expect(releaseScriptBody())->toContain("gates() {\n    gate bun run ci\n")
        ->and(json_decode((string) file_get_contents(base_path('package.json')), true)['scripts']['ci'])->toBe('bash scripts/ci-local.sh');

    foreach (['vendor/bin/pint', 'bun run lint:check', 'bun run lint:sh', 'bun run format:check', 'bun run lint:tailwind', 'bun run types:check', 'bun run build', 'vendor/bin/pest', 'bun run e2e:refresh', 'bun run e2e:install-refresh', 'bun run e2e:cloud-refresh', 'playwright test'] as $check) {
        expect($ci)->toContain($check);
    }
});

test('the script stays portable to the bash on a Mac', function () {
    $body = releaseScriptBody();

    foreach (['grep -P', 'sed -i', 'mapfile', 'readarray', 'echo -e', ',,}', '^^}', '[[ -v'] as $construct) {
        expect($body)->not->toContain($construct);
    }

    expect($body)->not->toContain('—');
});
