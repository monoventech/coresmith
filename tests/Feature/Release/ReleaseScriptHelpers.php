<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

const RELEASE_STUB = <<<'SH'
#!/bin/sh
printf '%s %s\n' '__NAME__' "$*" >> "$STUB_LOG"
__EXTRA__
code="${STUB_FAIL___UPPER__:-}"
if [ -n "$code" ] && printf '%s' "$*" | grep -qF -- "${STUB_FAIL_MATCH:-}"; then
    exit "$code"
fi
exit 0
SH;

const RELEASE_BUN_BUILD = <<<'SH'
if [ "$*" = "run build" ]; then
    for file in ${STUB_BUILD_EDITS:-}; do
        printf 'edited\n' >> "$file"
    done
fi
SH;

const RELEASE_GH = <<<'SH'
case "$1 $2" in
"release create")
    previous=""
    for argument in "$@"; do
        if [ "$previous" = "--notes-file" ]; then
            cp "$argument" "$STUB_NOTES"
        fi
        case "$argument" in
        *.asc) cp "$argument" "$STUB_ASSET" ;;
        */install.sh) cp "$argument" "$STUB_INSTALLER" ;;
        esac
        previous="$argument"
    done
    ;;
"pr create")
    previous=""
    for argument in "$@"; do
        if [ "$previous" = "--body-file" ]; then
            cp "$argument" "$STUB_PR_BODY"
        fi
        previous="$argument"
    done
    printf 'https://github.com/monoven/coresmith/pull/1\n'
    ;;
"pr list")
    if [ -f "$STUB_PR_VIEW" ]; then
        cat "$STUB_PR_VIEW"
    fi
    ;;
esac
SH;

function releaseScriptBody(): string
{
    return (string) file_get_contents(base_path('scripts/release.sh'));
}

function releaseScriptSandboxes(): stdClass
{
    static $registry = null;

    return $registry ??= (object) ['bases' => []];
}

function releaseScriptStub(string $path, string $name, string $extra = ''): void
{
    file_put_contents($path, strtr(RELEASE_STUB, ['__NAME__' => $name, '__UPPER__' => strtoupper($name), '__EXTRA__' => $extra]) . "\n");
    chmod($path, 0755);
}

function releaseScriptEnvironment(array $sandbox): array
{
    return [
        'PATH' => "{$sandbox['bin']}:" . getenv('PATH'),
        'CORESMITH_RELEASE_REPO' => 'monoven/coresmith',
        'CORESMITH_RELEASE_REMOTE' => false,
        'CORESMITH_SIGNING_KEY' => false,
        'GIT_AUTHOR_NAME' => 'Release Test',
        'GIT_AUTHOR_EMAIL' => 'release@example.invalid',
        'GIT_COMMITTER_NAME' => 'Release Test',
        'GIT_COMMITTER_EMAIL' => 'release@example.invalid',
        'GIT_CONFIG_GLOBAL' => $sandbox['gitconfig'],
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GNUPGHOME' => $sandbox['gnupg'],
        'STUB_LOG' => $sandbox['log'],
        'STUB_NOTES' => $sandbox['notes'],
        'STUB_ASSET' => $sandbox['asset'],
        'STUB_INSTALLER' => $sandbox['installer'],
        'STUB_PR_BODY' => $sandbox['body'],
        'STUB_PR_VIEW' => $sandbox['pr'],
    ];
}

function releaseGitProcess(array $sandbox, array $arguments, ?string $directory = null): Process
{
    $process = new Process(['git', ...$arguments], $directory ?? $sandbox['clone'], releaseScriptEnvironment($sandbox));
    $process->run();

    return $process;
}

function releaseGit(array $sandbox, array $arguments, ?string $directory = null): string
{
    $process = releaseGitProcess($sandbox, $arguments, $directory);

    if (!$process->isSuccessful()) {
        throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . $process->getErrorOutput());
    }

    return trim($process->getOutput());
}

function releaseOrigin(array $sandbox, array $arguments): string
{
    return releaseGit($sandbox, $arguments, $sandbox['origin']);
}

function releaseScriptSandbox(): array
{
    $base = realpath(sys_get_temp_dir()) . '/csr-' . Str::lower(Str::random(6));
    $sandbox = [
        'base' => $base,
        'origin' => "{$base}/origin.git",
        'clone' => "{$base}/clone",
        'bin' => "{$base}/bin",
        'gnupg' => "{$base}/g",
        'gitconfig' => "{$base}/gitconfig",
        'log' => "{$base}/calls.log",
        'notes' => "{$base}/notes.md",
        'asset' => "{$base}/install.sh.asc",
        'installer' => "{$base}/install.sh",
        'body' => "{$base}/pull-request.md",
        'pr' => "{$base}/pull-request.state",
    ];

    releaseScriptSandboxes()->bases[] = $sandbox;

    foreach (['clone', 'bin', 'gnupg'] as $directory) {
        mkdir($sandbox[$directory], 0700, true);
    }

    touch($sandbox['gitconfig']);
    touch($sandbox['log']);

    releaseScriptStub("{$sandbox['bin']}/bun", 'bun', RELEASE_BUN_BUILD);
    releaseScriptStub("{$sandbox['bin']}/php", 'php');
    releaseScriptStub("{$sandbox['bin']}/gh", 'gh', RELEASE_GH);

    $files = [
        'VERSION' => "0.1.0\n",
        'scripts/install.sh' => (string) file_get_contents(base_path('scripts/install.sh')),
        'scripts/release.sh' => releaseScriptBody(),
        '.gitignore' => "vendor/\n",
    ];

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname("{$sandbox['clone']}/{$path}"));
        file_put_contents("{$sandbox['clone']}/{$path}", $contents);
    }

    chmod("{$sandbox['clone']}/scripts/install.sh", 0755);
    chmod("{$sandbox['clone']}/scripts/release.sh", 0755);
    File::ensureDirectoryExists("{$sandbox['clone']}/vendor/bin");
    releaseScriptStub("{$sandbox['clone']}/vendor/bin/pint", 'pint');

    releaseGit($sandbox, ['init', '--quiet', '--bare', '--initial-branch=main', $sandbox['origin']], $base);
    releaseGit($sandbox, ['init', '--quiet', '--initial-branch=main']);
    releaseGit($sandbox, ['add', '--all']);
    releaseGit($sandbox, ['commit', '--quiet', '-m', 'chore: start']);
    releaseGit($sandbox, ['remote', 'add', 'origin', $sandbox['origin']]);
    releaseGit($sandbox, ['push', '--quiet', 'origin', 'main']);
    releaseGit($sandbox, ['branch', 'dev']);
    releaseGit($sandbox, ['push', '--quiet', 'origin', 'dev']);

    return $sandbox;
}

function releaseScriptRun(array $sandbox, array $arguments, array $env = [], ?string $input = null): Process
{
    $process = new Process(
        ['/bin/bash', 'scripts/release.sh', ...$arguments],
        $sandbox['clone'],
        [...releaseScriptEnvironment($sandbox), ...$env],
        $input,
        180,
    );
    $process->run();

    return $process;
}

function releaseOutput(Process $process): string
{
    return $process->getOutput() . $process->getErrorOutput();
}

function releaseCalls(array $sandbox): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($sandbox['log'])), fn(string $line) => $line !== ''));
}

function releaseLastCall(array $sandbox): string
{
    $calls = releaseCalls($sandbox);

    return (string) end($calls);
}

function releaseRefs(array $sandbox): string
{
    return releaseOrigin($sandbox, ['for-each-ref', '--format=%(refname) %(objectname)']);
}

function releaseCommit(array $sandbox, string $branch, string $subject, ?string $path = null, ?string $contents = null, bool $push = true): void
{
    $start = releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']);
    $path ??= 'changes/' . Str::slug($subject) . '.txt';

    releaseGit($sandbox, ['checkout', '--quiet', $branch]);
    File::ensureDirectoryExists(dirname("{$sandbox['clone']}/{$path}"));
    file_put_contents("{$sandbox['clone']}/{$path}", $contents ?? "{$subject}\n");
    releaseGit($sandbox, ['add', '--all']);
    releaseGit($sandbox, ['commit', '--quiet', '-m', $subject]);

    if ($push) {
        releaseGit($sandbox, ['push', '--quiet', 'origin', $branch]);
    }

    releaseGit($sandbox, ['checkout', '--quiet', $start]);
}

function releasePrState(array $sandbox, string $state, string $merge = ''): void
{
    file_put_contents($sandbox['pr'], "{$state}|{$merge}|https://github.com/monoven/coresmith/pull/1\n");
}

function releaseMergePr(array $sandbox, string $version, bool $squash = false): string
{
    $start = releaseGit($sandbox, ['rev-parse', '--abbrev-ref', 'HEAD']);

    releaseGit($sandbox, ['fetch', '--quiet', 'origin']);
    releaseGit($sandbox, ['checkout', '--quiet', '-B', 'merge-pull-request', 'origin/main']);

    if ($squash) {
        $messages = releaseGit($sandbox, ['log', '--reverse', '--format=* %B', "origin/main..origin/release/v{$version}"]);
        releaseGit($sandbox, ['merge', '--quiet', '--squash', "origin/release/v{$version}"]);
        releaseGit($sandbox, ['commit', '--quiet', '-m', "Release v{$version} (#1)\n\n{$messages}"]);
    } else {
        releaseGit($sandbox, ['merge', '--quiet', '--no-ff', '-m', "Merge pull request #1 from monoven/release/v{$version}", "origin/release/v{$version}"]);
    }

    releaseGit($sandbox, ['push', '--quiet', 'origin', 'HEAD:main']);
    $merge = releaseGit($sandbox, ['rev-parse', 'HEAD']);
    releaseGit($sandbox, ['checkout', '--quiet', $start]);
    releaseGit($sandbox, ['branch', '--quiet', '-D', 'merge-pull-request']);
    releasePrState($sandbox, 'MERGED', $merge);

    return $merge;
}

function releaseShip(array $sandbox, string $version, array $prepare = ['--yes', '--unsigned'], array $publish = ['--yes', '--unsigned']): Process
{
    $prepared = releaseScriptRun($sandbox, [$version, ...$prepare]);

    if (!$prepared->isSuccessful()) {
        throw new RuntimeException("Preparing v{$version} failed: " . releaseOutput($prepared));
    }

    releaseMergePr($sandbox, $version);

    return releaseScriptRun($sandbox, ['publish', $version, ...$publish]);
}

function releaseTag(array $sandbox, string $tag, string $ref = 'HEAD'): void
{
    releaseGit($sandbox, ['tag', '-a', $tag, '-m', "Release {$tag}", $ref]);
    releaseGit($sandbox, ['push', '--quiet', 'origin', "refs/tags/{$tag}"]);
}

function releaseHasGpg(): bool
{
    return (new ExecutableFinder)->find('gpg') !== null;
}

function releaseGpg(array $sandbox, array $arguments): Process
{
    $process = new Process(['gpg', '--batch', ...$arguments], $sandbox['clone'], ['GNUPGHOME' => $sandbox['gnupg']]);
    $process->run();

    return $process;
}

function releaseGpgKey(array $sandbox, string $name): string
{
    $email = Str::slug($name) . '@example.invalid';

    releaseGpg($sandbox, ['--passphrase', '', '--quick-gen-key', "{$name} <{$email}>", 'ed25519', 'sign', 'never']);
    preg_match('/^fpr:+([0-9A-F]+):/m', releaseGpg($sandbox, ['--with-colons', '--list-keys', $email])->getOutput(), $match);

    return $match[1] ?? throw new RuntimeException("gpg made no key for {$name}.");
}

function releaseShipKey(array $sandbox, string $branch, string $fingerprint, string $name): void
{
    releaseCommit($sandbox, $branch, "chore: ship the {$name} key", "resources/release-keys/{$name}.asc", releaseGpg($sandbox, ['--armor', '--export', $fingerprint])->getOutput());
}

function releaseScriptCleanUp(): void
{
    foreach (releaseScriptSandboxes()->bases as $sandbox) {
        if (releaseHasGpg()) {
            (new Process(['gpgconf', '--kill', 'all'], null, ['GNUPGHOME' => $sandbox['gnupg']]))->run();
        }

        File::deleteDirectory($sandbox['base']);
    }

    releaseScriptSandboxes()->bases = [];
}
