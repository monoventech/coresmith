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

test('a pre-release from main is refused', function () {
    $process = releaseScriptRun(releaseScriptSandbox(), ['1.0.0-beta.1', '--yes']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("Pre-releases can't be released from main. Switch to dev.");
});

test('a stable release from another branch is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseGit($sandbox, ['checkout', '--quiet', '-b', 'feature/x']);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("Stable releases come from main. On 'feature/x', use a pre-release such as 1.0.0-beta.1.");
});

test('on dev a bare version becomes the next beta', function () {
    $sandbox = releaseScriptSandbox();
    releaseGit($sandbox, ['checkout', '--quiet', 'dev']);
    releaseTag($sandbox, 'v1.0.0-beta.1');
    releaseTag($sandbox, 'v1.0.0-beta.2');

    $process = releaseScriptRun($sandbox, ['1.0.0'], [], "y\n");

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseOutput($process))->toContain('On dev a release is a pre-release, so this one is v1.0.0-beta.3.')
        ->and(releaseOutput($process))->toContain('Continue? [y/N]')
        ->and(releaseOrigin($sandbox, ['tag', '--list', 'v1.0.0-beta.3']))->toBe('v1.0.0-beta.3')
        ->and(releaseOrigin($sandbox, ['show', 'dev:VERSION']))->toBe('1.0.0-beta.3')
        ->and(releaseOrigin($sandbox, ['log', '-1', '--format=%s', 'dev']))->toBe('chore(release): v1.0.0-beta.3')
        ->and(releaseOrigin($sandbox, ['rev-parse', 'main']))->toBe(releaseGit($sandbox, ['rev-parse', 'origin/main']))
        ->and(releaseLastCall($sandbox))->toMatch('#^gh release create v1\.0\.0-beta\.3 --repo monoven/coresmith --title v1\.0\.0-beta\.3 --notes-file \S+ --verify-tag --prerelease scripts/install\.sh$#')
        ->and(file_get_contents($sandbox['notes']))->toBe("Pre-release v1.0.0-beta.3.\n");
});

test('on dev a version the beta suffix would make too long is refused before anything is pushed', function () {
    $sandbox = releaseScriptSandbox();
    releaseGit($sandbox, ['checkout', '--quiet', 'dev']);
    $version = '1.0.' . str_repeat('9', 54);

    $process = releaseScriptRun($sandbox, [$version], [], "y\n");

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("Version '{$version}-beta.1' is longer than 63 characters, so installs would refuse its tag. Use a shorter one.")
        ->and(releaseOrigin($sandbox, ['tag', '--list']))->toBe('');
});

test('an existing tag is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseTag($sandbox, 'v1.0.0');

    $process = releaseScriptRun($sandbox, ['v1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('Tag v1.0.0 already exists.');
});

test('a no at the prompt changes nothing', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    $before = releaseRefs($sandbox);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--unsigned'], [], "n\n");

    expect($process->getExitCode())->toBe(0)
        ->and(releaseOutput($process))->toContain('Aborted.')
        ->and(releaseCalls($sandbox))->toBe(['gh auth status'])
        ->and(releaseRefs($sandbox))->toBe($before);
});

test('a stable release without a signing key needs --unsigned', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $refused = releaseScriptRun($sandbox, ['1.0.0', '--yes']);

    expect($refused->getExitCode())->toBe(1)
        ->and(releaseOutput($refused))->toContain('No signing key is configured. Set one with git config user.signingkey <id>, or pass --unsigned to release v1.0.0 without a signature. Updaters will report it as unchecked.');

    $released = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($released->getExitCode())->toBe(0, releaseOutput($released))
        ->and(releaseOutput($released))->toContain('v1.0.0 will be unsigned. Updaters will report it as unchecked.')
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

    $released = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($released->getExitCode())->toBe(0, releaseOutput($released))
        ->and(releaseOrigin($sandbox, ['cat-file', '-p', 'v1.0.0']))->not->toContain('SIGNATURE');
});

test('a detached HEAD is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseGit($sandbox, ['checkout', '--quiet', '--detach']);

    $process = releaseScriptRun($sandbox, ['1.0.0-beta.1', '--yes']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('HEAD is detached. Check out main or dev, then release.');
});

test('a stable release merges dev, runs every gate, bumps, tags and publishes', function () {
    $sandbox = releaseScriptSandbox();
    releaseTag($sandbox, 'v0.1.0');
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    releaseCommit($sandbox, 'dev', 'fix: keep log colours');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process));

    $calls = array_values(array_filter(releaseCalls($sandbox), fn(string $line) => $line !== 'gh auth status'));

    expect(array_slice($calls, 0, -1))->toBe([
        'pint --test',
        'bun run lint:check',
        'bun run lint:sh',
        'bun run format:check',
        'bun run lint:tailwind',
        'bun run types:check',
        'php artisan test',
        'bun run e2e:refresh',
        'bun run e2e:install-refresh',
        'bun run test:e2e',
        'bun run build',
    ])->and(end($calls))->toMatch('#^gh release create v1\.0\.0 --repo monoven/coresmith --title v1\.0\.0 --notes-file \S+ --verify-tag --latest scripts/install\.sh$#');

    $parents = explode(' ', releaseOrigin($sandbox, ['rev-list', '--parents', '-n', '1', 'main^']));
    $changelog = releaseOrigin($sandbox, ['show', 'main:CHANGELOG.md']);

    expect(releaseOrigin($sandbox, ['log', '-1', '--format=%s', 'main']))->toBe('chore(release): v1.0.0')
        ->and($parents)->toHaveCount(3)
        ->and(releaseOrigin($sandbox, ['log', '-1', '--format=%s', 'main^']))->toBe('chore(release): merge dev into main for v1.0.0')
        ->and(releaseOrigin($sandbox, ['rev-parse', 'v1.0.0^{commit}']))->toBe(releaseOrigin($sandbox, ['rev-parse', 'main']))
        ->and(releaseOrigin($sandbox, ['cat-file', '-t', 'v1.0.0']))->toBe('tag')
        ->and(releaseOrigin($sandbox, ['show', 'main:VERSION']))->toBe('1.0.0')
        ->and(releaseOrigin($sandbox, ['show', 'main:scripts/install.sh']))->toContain("\nreadonly CORESMITH_VERSION=\"1.0.0\"\n")
        ->and(releaseOrigin($sandbox, ['ls-tree', 'main', 'scripts/install.sh']))->toStartWith('100755 ')
        ->and($changelog)->toMatch('/^# Changelog\n\n## v1\.0\.0 \(\d{4}-\d{2}-\d{2}\)\n\n### Added/')
        ->and($changelog)->toContain("### Added\n- feat: deploy faster")
        ->and($changelog)->toContain("### Fixed\n- fix: keep log colours")
        ->and($changelog)->not->toContain('chore')
        ->and($changelog)->not->toContain('—')
        ->and(releaseOutput($process))->not->toContain('—')
        ->and(file_get_contents($sandbox['notes']))->toBe("### Added\n- feat: deploy faster\n\n### Fixed\n- fix: keep log colours\n")
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('')
        ->and(releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('main');
});

test('the first stable release says so in the changelog', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseOrigin($sandbox, ['show', 'main:CHANGELOG.md']))->toMatch('/^# Changelog\n\n## v1\.0\.0 \(\d{4}-\d{2}-\d{2}\)\n\nInitial release\.$/')
        ->and(file_get_contents($sandbox['notes']))->toBe("Initial release.\n");
});

test('the changelog starts from the previous stable release, not a beta', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: one');

    expect(releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'])->getExitCode())->toBe(0);

    releaseGit($sandbox, ['checkout', '--quiet', 'dev']);
    releaseCommit($sandbox, 'dev', 'fix: two');

    $beta = releaseScriptRun($sandbox, ['1.0.1-beta.1', '--yes']);

    expect($beta->getExitCode())->toBe(0, releaseOutput($beta));

    releaseCommit($sandbox, 'dev', 'feat: three');
    releaseGit($sandbox, ['checkout', '--quiet', 'main']);

    $stable = releaseScriptRun($sandbox, ['1.0.1', '--yes', '--unsigned']);

    expect($stable->getExitCode())->toBe(0, releaseOutput($stable));

    $changelog = releaseOrigin($sandbox, ['show', 'main:CHANGELOG.md']);
    $entry = substr($changelog, strpos($changelog, '## v1.0.1 ('), strpos($changelog, '## v1.0.0 (') - strpos($changelog, '## v1.0.1 ('));

    expect($changelog)->toStartWith("# Changelog\n\n## v1.0.1 (")
        ->and($entry)->toContain("### Added\n- feat: three")
        ->and($entry)->toContain("### Fixed\n- fix: two")
        ->and($entry)->not->toContain('feat: one')
        ->and($entry)->not->toContain('chore(release)')
        ->and($changelog)->toContain('Initial release.')
        ->and(releaseOrigin($sandbox, ['show', 'main:VERSION']))->toBe('1.0.1')
        ->and(releaseOrigin($sandbox, ['show', 'main:scripts/install.sh']))->toContain("\nreadonly CORESMITH_VERSION=\"1.0.1\"\n")
        ->and(releaseOrigin($sandbox, ['rev-parse', 'v1.0.1^{commit}']))->toBe(releaseOrigin($sandbox, ['rev-parse', 'main']));
});

test('a merge from dev that conflicts beyond the version files is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'main', 'docs: main readme', 'README.md', "main\n");
    releaseCommit($sandbox, 'dev', 'docs: dev readme', 'README.md', "dev\n");
    $main = releaseGit($sandbox, ['rev-parse', 'main']);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('The merge from dev conflicts. Resolve it on main, then release.')
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(releaseGit($sandbox, ['rev-parse', 'main']))->toBe($main)
        ->and(releaseGitProcess($sandbox, ['rev-parse', '-q', '--verify', 'MERGE_HEAD'])->isSuccessful())->toBeFalse()
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('');
});

test('local dev must match the remote before a stable release', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: unpushed', push: false);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('Local dev differs from origin/dev. Pull or push it, then release.');
});

test('a stable release with nothing new is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseTag($sandbox, 'v1.0.0');

    $process = releaseScriptRun($sandbox, ['1.0.1', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('Nothing to release: main has no commits since v1.0.0.');
});

test('a failing gate leaves nothing behind', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    $before = releaseRefs($sandbox);
    $main = releaseGit($sandbox, ['rev-parse', 'main']);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'], ['STUB_FAIL_PHP' => '1', 'STUB_FAIL_MATCH' => 'artisan test']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('php artisan test failed. Fix it on dev, then release.')
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(releaseCalls($sandbox))->not->toContain('bun run e2e:refresh')
        ->and(releaseGit($sandbox, ['rev-parse', 'main']))->toBe($main)
        ->and(releaseGit($sandbox, ['tag', '--list', 'v1.0.0']))->toBe('')
        ->and(releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('main')
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('')
        ->and(releaseRefs($sandbox))->toBe($before);
});

test('a rejected push leaves nothing behind and never pushes main without its tag', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    file_put_contents("{$sandbox['origin']}/hooks/update", "#!/bin/sh\ncase \"\$1\" in refs/tags/*) exit 1 ;; esac\nexit 0\n");
    chmod("{$sandbox['origin']}/hooks/update", 0755);
    $before = releaseRefs($sandbox);
    $main = releaseGit($sandbox, ['rev-parse', 'main']);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('origin refused the push.')
        ->and(releaseOutput($process))->toContain('Release v1.0.0 stopped. Nothing was pushed.')
        ->and(releaseRefs($sandbox))->toBe($before)
        ->and(releaseGit($sandbox, ['rev-parse', 'main']))->toBe($main)
        ->and(releaseGit($sandbox, ['tag', '--list', 'v1.0.0']))->toBe('')
        ->and(releaseGit($sandbox, ['status', '--porcelain']))->toBe('');
});

test('a failed github release keeps the pushed tag and says how to finish', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'], ['STUB_FAIL_GH' => '1', 'STUB_FAIL_MATCH' => 'release create']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain("v1.0.0 is pushed, but the GitHub release wasn't created. Finish it with:")
        ->and(releaseOutput($process))->toMatch('#  gh release create v1\.0\.0 --repo monoven/coresmith --title v1\.0\.0 --notes-file (\S+) --verify-tag --latest scripts/install\.sh#')
        ->and(releaseOutput($process))->not->toContain('Nothing was pushed')
        ->and(releaseOrigin($sandbox, ['tag', '--list', 'v1.0.0']))->toBe('v1.0.0')
        ->and(releaseOrigin($sandbox, ['log', '-1', '--format=%s', 'main']))->toBe('chore(release): v1.0.0');

    preg_match('#--notes-file (\S+)#', releaseOutput($process), $notes);

    expect(is_file($notes[1]))->toBeTrue();

    File::deleteDirectory(dirname($notes[1]));
});

test('a build that changes other tracked files is refused', function () {
    $sandbox = releaseScriptSandbox();
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');
    $main = releaseGit($sandbox, ['rev-parse', 'main']);

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned'], ['STUB_BUILD_EDITS' => 'VERSION']);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('The build changed tracked files: VERSION. Commit them on dev first.')
        ->and(releaseGit($sandbox, ['rev-parse', 'main']))->toBe($main)
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

test('the GitHub repository is read from the remote address', function (string $url) {
    $sandbox = releaseScriptSandbox();
    file_put_contents($sandbox['gitconfig'], "[url \"{$sandbox['origin']}\"]\n\tinsteadOf = {$url}\n");
    releaseGit($sandbox, ['remote', 'set-url', 'origin', $url]);
    releaseGit($sandbox, ['checkout', '--quiet', 'dev']);

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

test('with a signing key every tag is signed and the installer signature is attached', function () {
    if (!releaseHasGpg()) {
        $this->markTestSkipped('gpg is not installed on this machine; CI installs it.');
    }

    $sandbox = releaseScriptSandbox();
    $key = releaseGpgKey($sandbox, 'Release Key');
    releaseGit($sandbox, ['config', 'user.signingkey', $key]);
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseGitProcess($sandbox, ['verify-tag', 'v1.0.0'], $sandbox['origin'])->isSuccessful())->toBeTrue()
        ->and(releaseLastCall($sandbox))->toMatch('# scripts/install\.sh \S+/install\.sh\.asc$#')
        ->and(releaseGpg($sandbox, ['--verify', $sandbox['asset'], "{$sandbox['clone']}/scripts/install.sh"])->isSuccessful())->toBeTrue();
});

test('a signing key is checked against the release keys the panel ships', function () {
    if (!releaseHasGpg()) {
        $this->markTestSkipped('gpg is not installed on this machine; CI installs it.');
    }

    $sandbox = releaseScriptSandbox();
    $signing = releaseGpgKey($sandbox, 'Release Key');
    releaseShipKey($sandbox, 'main', releaseGpgKey($sandbox, 'Other Key'), 'other');
    releaseGit($sandbox, ['config', 'user.signingkey', $signing]);
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $refused = releaseScriptRun($sandbox, ['1.0.0', '--yes']);

    expect($refused->getExitCode())->toBe(1)
        ->and(releaseOutput($refused))->toContain("Your signing key isn't one of resources/release-keys, so every updater would reject v1.0.0.");

    releaseShipKey($sandbox, 'main', $signing, 'release');

    $released = releaseScriptRun($sandbox, ['1.0.0', '--yes']);

    expect($released->getExitCode())->toBe(0, releaseOutput($released))
        ->and(releaseGitProcess($sandbox, ['verify-tag', 'v1.0.0'], $sandbox['origin'])->isSuccessful())->toBeTrue();
});

test('a release that ships keys must be signed', function (string $branch, array $arguments, bool $configured) {
    if (!releaseHasGpg()) {
        $this->markTestSkipped('gpg is not installed on this machine; CI installs it.');
    }

    $sandbox = releaseScriptSandbox();
    $key = releaseGpgKey($sandbox, 'Release Key');
    releaseShipKey($sandbox, $branch, $key, 'release');
    releaseGit($sandbox, ['checkout', '--quiet', $branch]);

    if ($configured) {
        releaseGit($sandbox, ['config', 'user.signingkey', $key]);
    }

    $process = releaseScriptRun($sandbox, $arguments);

    expect($process->getExitCode())->toBe(1)
        ->and(releaseOutput($process))->toContain('This release ships release keys, so every updater requires a signature. Configure the signing key, then release.');
})->with([
    'no key' => ['main', ['1.0.0', '--yes'], false],
    '--unsigned with a key' => ['main', ['1.0.0', '--yes', '--unsigned'], true],
    'a pre-release with no key' => ['dev', ['1.0.0-rc.1', '--yes'], false],
]);

test('--unsigned wins over a configured key while no release key ships', function () {
    if (!releaseHasGpg()) {
        $this->markTestSkipped('gpg is not installed on this machine; CI installs it.');
    }

    $sandbox = releaseScriptSandbox();
    releaseGit($sandbox, ['config', 'user.signingkey', releaseGpgKey($sandbox, 'Release Key')]);
    releaseCommit($sandbox, 'dev', 'feat: deploy faster');

    $process = releaseScriptRun($sandbox, ['1.0.0', '--yes', '--unsigned']);

    expect($process->getExitCode())->toBe(0, releaseOutput($process))
        ->and(releaseOutput($process))->toContain('v1.0.0 will be unsigned.')
        ->and(releaseOrigin($sandbox, ['cat-file', '-p', 'v1.0.0']))->not->toContain('BEGIN PGP SIGNATURE')
        ->and(releaseLastCall($sandbox))->toEndWith(' scripts/install.sh')
        ->and(is_file($sandbox['asset']))->toBeFalse();
});

test('the release runs every gate the project requires', function () {
    $body = releaseScriptBody();

    foreach (['./vendor/bin/pint --test', 'bun run lint:check', 'bun run lint:sh', 'bun run format:check', 'bun run lint:tailwind', 'bun run types:check', 'bun run build', 'php artisan test', 'bun run test:e2e'] as $gate) {
        expect($body)->toContain($gate);
    }
});

test('the script stays portable to the bash on a Mac', function () {
    $body = releaseScriptBody();

    foreach (['grep -P', 'sed -i', 'mapfile', 'readarray', 'echo -e', ',,}', '^^}', '[[ -v'] as $construct) {
        expect($body)->not->toContain($construct);
    }

    expect($body)->not->toContain('—');
});
