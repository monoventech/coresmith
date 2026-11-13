<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitProviderException;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\GitFailure;
use App\Services\Git\CloneToken;
use App\Services\Git\DeployKeyManager;
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitProviderFactory;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Str;
use Throwable;

final class RepositoryReader
{
    public const MARKER = 'CORESMITH_READ=1';

    private const SETUP_FAILED = 70;

    private const SECONDS = 300;

    private const RUNTIMES = ['php', 'nodejs', 'bun', 'deno', 'ruby'];

    private const SCRIPT = <<<'BASH'
    set -o pipefail
    export GIT_TERMINAL_PROMPT=0
    limit=; if command -v timeout >/dev/null 2>&1; then limit='timeout 120'; fi
    {credentials}
    dir=$(mktemp -d /tmp/coresmith-read.XXXXXXXX) || exit 70
    trap 'rm -rf "$dir"' EXIT
    $limit git clone --quiet --depth 1 --filter=blob:none --no-checkout --single-branch --branch {branch} -- {url} "$dir/repo" 2>&1 || exit 71
    cd "$dir/repo" || exit 70
    git sparse-checkout set --no-cone {patterns} 2>&1 || exit 72
    $limit git -c core.symlinks=false checkout --quiet 2>&1 || exit 73
    printf '@@commit %s\n' "$(git rev-parse HEAD)"
    {folder}
    names=$(git ls-tree --name-only HEAD {tree})
    for f in {lockfiles}; do
      if printf '%s\n' "$names" | grep -qxF -- {prefix}"$f"; then : > "$app/$f"; fi
    done
    for r in {runtimes}; do
      for v in $(ls -1 {installs}/"$r" 2>/dev/null); do printf '@@installed %s %s\n' "$r" "$v"; done
    done
    echo {probe} | base64 -d | CORESMITH_PROBE_DIR="$app" bash -s
    BASH;

    public function __construct(
        private readonly SSHConnectionManager $connections,
        private readonly RepositoryProbe $probe,
        private readonly DeployKeyManager $keys,
        private readonly CloneToken $tokens,
    ) {}

    public function read(Server $server, RepositoryAccess $access, string $branch, string $directory): RepositoryRead
    {
        SafeName::assertBranch($branch);
        $subfolder = trim($directory, '/');

        if (preg_match(SafeName::RELATIVE_PATH_PATTERN, $directory) !== 1) {
            return RepositoryRead::failed("{$directory} isn't a folder Coresmith can read.", true);
        }

        try {
            $connection = $this->connections->for($server);
        } catch (Throwable) {
            return RepositoryRead::failed("Coresmith couldn't reach {$server->name} over SSH, so it couldn't read the repository. Try again once the server is back.", false);
        }

        $name = 'read-' . Str::lower(Str::random(20));
        $token = null;
        $secret = null;

        try {
            if ($access->github !== null) {
                $token = app(GitProviderFactory::class)->github($access->github)->cloneToken((string) $access->repositoryId);
                $secret = $this->tokens->pathFor($name);
                $this->install($connection, $secret, $this->tokens->content($token));
                $credentials = 'export GIT_CONFIG_COUNT=2 GIT_CONFIG_KEY_0=credential.helper GIT_CONFIG_VALUE_0= GIT_CONFIG_KEY_1=credential.helper GIT_CONFIG_VALUE_1=' . escapeshellarg($this->tokens->helper($secret));
            } elseif ($access->application !== null) {
                $application = $this->keys->ensure($access->application);
                $secret = DeployKeyManager::KEY_DIR . '/' . $name;
                $this->install($connection, $secret, (string) $application->deploy_key_private);
                $credentials = 'export GIT_SSH_COMMAND=' . escapeshellarg($this->keys->sshCommand($application, $secret));
            } else {
                $credentials = '';
            }

            $result = $connection->execute($this->command($access->url, $branch, $subfolder, $credentials), sudo: true, timeout: self::SECONDS);
        } catch (GitHubInstallationException $refusal) {
            return RepositoryRead::failed($this->gitHubRefusal($access, $refusal), $refusal->kind !== GitHubInstallationException::UNREACHABLE);
        } catch (GitProviderException $refusal) {
            return RepositoryRead::failed("GitHub wouldn't give Coresmith a token to read {$access->fullName}: {$refusal->getMessage()}", false);
        } catch (Throwable) {
            return RepositoryRead::failed("Coresmith lost its connection to {$server->name} while reading the repository. Try again.", false);
        } finally {
            if ($secret !== null) {
                rescue(fn() => $connection->execute('rm -f ' . escapeshellarg($secret), sudo: true), report: false);
            }

            if ($token !== null) {
                rescue(fn() => app(GitHubAppApi::class)->revokeToken($token), report: false);
            }

            $this->connections->disconnect($server);
        }

        return $this->result($result->exitCode, $result->output, $server, $access, $branch, $subfolder);
    }

    private function install(SSHConnection $connection, string $path, #[\SensitiveParameter] string $content): void
    {
        $connection->execute('mkdir -p ' . escapeshellarg(DeployKeyManager::KEY_DIR) . ' && chown root:root ' . escapeshellarg(DeployKeyManager::KEY_DIR) . ' && chmod 700 ' . escapeshellarg(DeployKeyManager::KEY_DIR), sudo: true)->throw();
        $connection->execute('install -m 600 /dev/null ' . escapeshellarg($path), sudo: true)->throw();
        $connection->uploadContent($content, $path);
    }

    private function command(string $url, string $branch, string $subfolder, string $credentials): string
    {
        return 'echo ' . escapeshellarg(base64_encode($this->script($url, $branch, $subfolder, $credentials))) . ' | base64 -d | ' . self::MARKER . ' bash -s';
    }

    public function script(string $url, string $branch, string $subfolder, string $credentials = ''): string
    {
        $prefix = $subfolder === '' ? '' : $subfolder . '/';
        $patterns = array_map(fn(string $file): string => escapeshellarg('/' . $prefix . $file), [...RepositoryProbe::FILES, ...RepositoryProbe::MARKERS, RepositoryProbe::COMPOSER_LOCK]);
        $folder = $subfolder === ''
            ? 'app="$dir/repo"'
            : 'if [ "$(git cat-file -t ' . escapeshellarg('HEAD:' . $subfolder) . ' 2>/dev/null)" != tree ]; then printf \'@@nodir\n\'; exit 0; fi' . "\n" . 'app="$dir/repo"/' . escapeshellarg($subfolder) . "\n" . 'mkdir -p "$app"';

        return strtr(self::SCRIPT, [
            '{credentials}' => $credentials,
            '{branch}' => escapeshellarg($branch),
            '{url}' => escapeshellarg($url),
            '{patterns}' => implode(' ', $patterns),
            '{folder}' => $folder,
            '{tree}' => $subfolder === '' ? '' : '-- ' . escapeshellarg($prefix),
            '{prefix}' => escapeshellarg($prefix),
            '{lockfiles}' => implode(' ', RepositoryProbe::LOCKFILES),
            '{probe}' => escapeshellarg(base64_encode($this->probe->script())),
            '{runtimes}' => implode(' ', self::RUNTIMES),
            '{installs}' => escapeshellarg(AsdfManager::INSTALL_DIR),
        ]) . "\n";
    }

    private function result(int $exitCode, string $output, Server $server, RepositoryAccess $access, string $branch, string $subfolder): RepositoryRead
    {
        if ($exitCode === 0) {
            if (preg_match('/^@@nodir$/m', $output) === 1) {
                return RepositoryRead::failed("There is no /{$subfolder} folder in the {$branch} branch.", true);
            }

            $commit = preg_match('/^@@commit ([0-9a-f]{40})$/m', $output, $matches) === 1 ? $matches[1] : null;

            return new RepositoryRead($this->probe->parse($output), $commit, installed: $this->installed($output));
        }

        if ($exitCode === self::SETUP_FAILED) {
            return RepositoryRead::failed("Coresmith couldn't make a temporary folder on {$server->name} to read the repository. Check the server has free disk space, then try again.", false);
        }

        $failure = GitFailure::from($output, new Application(['repository_url' => $access->url, 'repository_branch' => $branch]));
        $url = GitFailure::displayUrl($access->url);
        $host = GitFailure::host($url);

        return match ($failure->kind) {
            GitFailure::NOT_FOUND => RepositoryRead::failed("There is no repository at {$url}. Check the URL.", true),
            GitFailure::UNREADABLE => RepositoryRead::failed($this->unreadable($access, $url, $host), true),
            GitFailure::BRANCH => RepositoryRead::failed("Branch {$branch} isn't in the repository. Check the branch name.", true),
            GitFailure::DNS => RepositoryRead::failed("{$server->name} couldn't look up {$host}. Check its DNS settings, then try again.", false),
            GitFailure::NETWORK => RepositoryRead::failed("{$server->name} couldn't reach {$host}. Check its firewall allows outbound HTTPS and SSH, then try again.", false),
            default => RepositoryRead::failed("Git stopped on {$server->name} with an error Coresmith doesn't recognise: " . Str::limit(trim((string) Str::afterLast(trim($output), "\n")), 200) . ' Try again.', false),
        };
    }

    /**
     * @return array<string, list<string>>
     */
    private function installed(string $output): array
    {
        $installed = [];

        preg_match_all('/^@@installed (' . implode('|', self::RUNTIMES) . ') (\d[0-9A-Za-z.+-]*)$/m', $output, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $runtime, $version]) {
            $installed[$runtime][] = $version;
        }

        return $installed;
    }

    private function unreadable(RepositoryAccess $access, string $url, string $host): string
    {
        return match (true) {
            $access->github !== null => "GitHub didn't let Coresmith read {$access->fullName}. Check the repository is still selected for Coresmith's GitHub app, then try again.",
            $access->application !== null => "{$host} refused this app's deploy key. Add the key shown under Source to the repository's deploy keys, then check again.",
            default => "{$host} wouldn't let Coresmith read {$url} without credentials. If it's private, connect GitHub in Settings, Git, or use a git@ URL so Coresmith reads it with a deploy key.",
        };
    }

    private function gitHubRefusal(RepositoryAccess $access, GitHubInstallationException $refusal): string
    {
        return GitFailure::adviceFor($refusal, new Application(['source_provider_id' => $access->github?->getKey(), 'repository_url' => $access->url]));
    }
}
