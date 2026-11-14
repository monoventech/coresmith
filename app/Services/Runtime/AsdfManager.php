<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Exceptions\InvalidRuntimeException;
use App\Exceptions\PackageSourceFailed;
use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\SafeName;
use App\Services\Server\AptPackageManager;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

final class AsdfManager
{
    public const DATA_DIR = '/opt/asdf-data';

    public const INSTALL_DIR = self::DATA_DIR . '/installs';

    public const SYSTEM_USER = 'coresmith';

    public const CORESMITH_HOST_USER = 'coresmith-runtime';

    public const PATH = AsdfInstaller::ASDF_DIR . '/bin:' . self::DATA_DIR . '/shims:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    public const PLUGINS = ['php', 'nodejs', 'bun', 'deno', 'ruby'];

    public const RUNTIMES = [...self::PLUGINS, 'python', 'go', 'java'];

    public const UV_DIR = self::DATA_DIR . '/uv';

    public const DOWNLOAD_RETRIES = 2;

    public const INDEX_LOCK_RETRIES = 30;

    public const INSTALL_SECONDS = 1800;

    private const BUILD_ENVIRONMENT = [
        'php' => [
            'PHP_BUILD_CONFIGURE_OPTS' => '--with-pgsql --with-pdo-pgsql',
            'PHP_BUILD_INSTALL_EXTENSION' => 'redis',
        ],
        'ruby' => [
            'RUBY_CONFIGURE_OPTS' => '--disable-install-doc',
        ],
    ];

    private const DOWNLOAD_ERRORS = ['curl: (', 'Could not resolve host', 'Failed to download', 'Connection timed out', 'Connection reset', 'ECONNRESET', 'ETIMEDOUT', 'ENOTFOUND', 'EAI_AGAIN', 'unable to download', 'SSL_ERROR_SYSCALL', 'Temporary failure resolving', 'Could not connect to', 'Unable to connect to'];

    private const INDEX_LOCKED = 'Could not get lock';

    private const UV_SCRIPT = <<<'BASH'
    set -e
    UV_VERSION="$CORESMITH_UV_VERSION"
    case "$(uname -m)" in
      x86_64|amd64) arch=x86_64; sum="$CORESMITH_UV_SHA256_X86_64" ;;
      aarch64|arm64) arch=aarch64; sum="$CORESMITH_UV_SHA256_AARCH64" ;;
      *) echo "Coresmith can't install uv on $(uname -m) servers, so it can't install Python here."; exit 1 ;;
    esac
    tmp="$(mktemp -d "${TMPDIR:-/tmp}/coresmith-uv.XXXXXXXX")"
    trap 'rm -rf "$tmp"' EXIT
    curl -fsSL -o "$tmp/uv.tar.gz" "https://github.com/astral-sh/uv/releases/download/$UV_VERSION/uv-$arch-unknown-linux-gnu.tar.gz"
    echo "$sum  $tmp/uv.tar.gz" | sha256sum -c - >/dev/null 2>&1 || { echo "The uv $UV_VERSION download did not match its published checksum, so it was not used."; exit 1; }
    tar -xzf "$tmp/uv.tar.gz" -C "$tmp"
    mkdir -p "$CORESMITH_UV_DIR/$UV_VERSION"
    for tool in uv uvx; do
      cp "$tmp/uv-$arch-unknown-linux-gnu/$tool" "$CORESMITH_UV_DIR/$UV_VERSION/.$tool.$$"
      chmod 0755 "$CORESMITH_UV_DIR/$UV_VERSION/.$tool.$$"
      mv -f "$CORESMITH_UV_DIR/$UV_VERSION/.$tool.$$" "$CORESMITH_UV_DIR/$UV_VERSION/$tool"
    done
    BASH;

    private const PYTHON_SCRIPT = <<<'BASH'
    set -e
    mkdir -p "$CORESMITH_PYTHON_DIR"
    stage="$(mktemp -d "$CORESMITH_PYTHON_DIR/.coresmith-stage.XXXXXXXX")"
    trap 'rm -rf "$stage"' EXIT
    "$CORESMITH_UV" python install "$CORESMITH_PYTHON" --install-dir "$stage" --no-bin
    found="$(find "$stage" -mindepth 1 -maxdepth 1 -type d -name "cpython-$CORESMITH_PYTHON-*" | head -n 1)"
    [ -n "$found" ] || { echo "uv finished without installing Python $CORESMITH_PYTHON."; exit 1; }
    rm -rf "$CORESMITH_PYTHON_DIR/$CORESMITH_PYTHON"
    mv "$found" "$CORESMITH_PYTHON_DIR/$CORESMITH_PYTHON"
    grep -rIl -- "$found" "$CORESMITH_PYTHON_DIR/$CORESMITH_PYTHON" | while IFS= read -r file; do sed -i "s#$found#$CORESMITH_PYTHON_DIR/$CORESMITH_PYTHON#g" "$file"; done
    ln -sfn python3 "$CORESMITH_PYTHON_DIR/$CORESMITH_PYTHON/bin/python"
    "$CORESMITH_PYTHON_DIR/$CORESMITH_PYTHON/bin/python" -m compileall -q -j 0 -x '/(test|tests|idle_test)/' "$CORESMITH_PYTHON_DIR/$CORESMITH_PYTHON/lib" >/dev/null 2>&1 || echo "Some of Python $CORESMITH_PYTHON's standard library didn't precompile, so apps may start a little slower."
    BASH;

    private const GO_SCRIPT = <<<'BASH'
    set -e
    case "$(uname -m)" in
      x86_64|amd64) arch=amd64 ;;
      aarch64|arm64) arch=arm64 ;;
      *) echo "Go publishes no build for $(uname -m) servers, so Coresmith can't install it here."; exit 1 ;;
    esac
    mkdir -p "$CORESMITH_GO_DIR"
    stage="$(mktemp -d "$CORESMITH_GO_DIR/.coresmith-stage.XXXXXXXX")"
    trap 'rm -rf "$stage"' EXIT
    archive="https://dl.google.com/go/go${CORESMITH_GO}.linux-${arch}.tar.gz"
    curl -fsSL -o "$stage/go.tar.gz" "$archive"
    curl -fsSL -o "$stage/go.tar.gz.sha256" "$archive.sha256"
    echo "$(tr -d '[:space:]' < "$stage/go.tar.gz.sha256")  $stage/go.tar.gz" | sha256sum -c - >/dev/null 2>&1 || { echo "The Go $CORESMITH_GO download did not match its published checksum, so it was not used."; exit 1; }
    mkdir "$stage/go"
    tar -xzf "$stage/go.tar.gz" -C "$stage/go" --strip-components=1
    rm -rf "$CORESMITH_GO_DIR/$CORESMITH_GO"
    mv "$stage/go" "$CORESMITH_GO_DIR/$CORESMITH_GO"
    BASH;

    private const JAVA_SCRIPT = <<<'BASH'
    set -e
    case "$(uname -m)" in
      x86_64|amd64) arch=x64 ;;
      aarch64|arm64) arch=aarch64 ;;
      *) echo "Temurin publishes no Java build for $(uname -m) servers, so Coresmith can't install it here."; exit 1 ;;
    esac
    major="${CORESMITH_JAVA%%.*}"
    mkdir -p "$CORESMITH_JAVA_DIR"
    stage="$(mktemp -d "$CORESMITH_JAVA_DIR/.coresmith-stage.XXXXXXXX")"
    trap 'rm -rf "$stage"' EXIT
    archive="https://github.com/adoptium/temurin${major}-binaries/releases/download/jdk-${CORESMITH_JAVA}%2B${CORESMITH_JAVA_BUILD}/OpenJDK${major}U-jdk_${arch}_linux_hotspot_${CORESMITH_JAVA}_${CORESMITH_JAVA_BUILD}.tar.gz"
    curl -fsSL -o "$stage/java.tar.gz" "$archive"
    curl -fsSL -o "$stage/java.tar.gz.sha256.txt" "$archive.sha256.txt"
    echo "$(cut -d ' ' -f 1 < "$stage/java.tar.gz.sha256.txt")  $stage/java.tar.gz" | sha256sum -c - >/dev/null 2>&1 || { echo "The Java $CORESMITH_JAVA download did not match its published checksum, so it was not used."; exit 1; }
    mkdir "$stage/java"
    tar -xzf "$stage/java.tar.gz" -C "$stage/java" --strip-components=1
    rm -rf "$CORESMITH_JAVA_DIR/$CORESMITH_JAVA"
    mv "$stage/java" "$CORESMITH_JAVA_DIR/$CORESMITH_JAVA"
    BASH;

    public static function assertValid(string $runtime, string $version): void
    {
        if (!in_array($runtime, self::RUNTIMES, true)) {
            throw InvalidRuntimeException::runtime($runtime);
        }

        if (preg_match('/^[A-Za-z0-9._-]+\z/', $version) !== 1) {
            throw InvalidRuntimeException::version($version);
        }
    }

    public function isInstalled(SSHConnection $connection, string $runtime, string $version): bool
    {
        self::assertValid($runtime, $version);

        $path = escapeshellarg($this->versionPath($runtime, $version));

        return trim($connection->execute("test -d {$path} && echo yes || echo no")->output) === 'yes';
    }

    public function install(SSHConnection $connection, Server $server, string $runtime, string $version): void
    {
        self::assertValid($runtime, $version);

        if ($this->isInstalled($connection, $runtime, $version)) {
            return;
        }

        if ($runtime === 'python') {
            $this->installPython($connection, $version);

            return;
        }

        if ($runtime === 'java') {
            $build = config('coresmith.temurin_builds')[$version] ?? null;

            if (!is_string($build) || preg_match('/^\d+\z/', $build) !== 1) {
                throw InvalidRuntimeException::version($version);
            }

            $this->withDownloadRetries($connection, $this->asRuntimeUser($connection, self::JAVA_SCRIPT, [
                'CORESMITH_JAVA' => $version,
                'CORESMITH_JAVA_BUILD' => $build,
                'CORESMITH_JAVA_DIR' => self::INSTALL_DIR . '/java',
            ]), self::INSTALL_SECONDS);

            return;
        }

        if ($runtime === 'go') {
            $this->withDownloadRetries($connection, $this->asRuntimeUser($connection, self::GO_SCRIPT, [
                'CORESMITH_GO' => $version,
                'CORESMITH_GO_DIR' => self::INSTALL_DIR . '/go',
            ]), self::INSTALL_SECONDS);

            return;
        }

        $dependencies = BuildDependencies::for($runtime, $server->os_name);

        if ($dependencies !== []) {
            $packages = PackageManagerFactory::for($server);

            $this->refreshIndex($connection, $server, $packages->update());
            $this->withDownloadRetries($connection, $packages->install($dependencies), SSHTransport::PACKAGE_SECONDS);
        }

        $this->withDownloadRetries($connection, $this->asRuntimeUser($connection, 'asdf plugin update "$CORESMITH_RUNTIME"', ['CORESMITH_RUNTIME' => $runtime]), SSHTransport::COMMAND_SECONDS);
        $this->withDownloadRetries($connection, $this->asdfCommand($connection, 'install', $runtime, $version, self::BUILD_ENVIRONMENT[$runtime] ?? []), self::INSTALL_SECONDS);
    }

    public function uvPath(): string
    {
        return self::UV_DIR . '/' . $this->uvVersion() . '/uv';
    }

    public function uvInstalled(SSHConnection $connection): bool
    {
        return $connection->execute('test -x ' . escapeshellarg($this->uvPath()))->successful();
    }

    public function ensureUv(SSHConnection $connection): bool
    {
        if ($this->uvInstalled($connection)) {
            return false;
        }

        $sums = (array) config('coresmith.uv.sha256');

        $this->withDownloadRetries($connection, $this->asRuntimeUser($connection, self::UV_SCRIPT, [
            'CORESMITH_UV_VERSION' => $this->uvVersion(),
            'CORESMITH_UV_DIR' => self::UV_DIR,
            'CORESMITH_UV_SHA256_X86_64' => (string) ($sums['x86_64'] ?? ''),
            'CORESMITH_UV_SHA256_AARCH64' => (string) ($sums['aarch64'] ?? ''),
        ]), SSHTransport::COMMAND_SECONDS);

        return true;
    }

    public function uvVersion(): string
    {
        $version = (string) config('coresmith.uv.version');

        if (preg_match('/^\d+\.\d+\.\d+\z/', $version) !== 1) {
            throw InvalidRuntimeException::version($version);
        }

        return $version;
    }

    private function installPython(SSHConnection $connection, string $version): void
    {
        $this->ensureUv($connection);

        $this->withDownloadRetries($connection, $this->asRuntimeUser($connection, self::PYTHON_SCRIPT, [
            'CORESMITH_UV' => $this->uvPath(),
            'CORESMITH_PYTHON' => $version,
            'CORESMITH_PYTHON_DIR' => self::INSTALL_DIR . '/python',
        ]), self::INSTALL_SECONDS);
    }

    public function withDownloadRetries(SSHConnection $connection, string $command, int $timeout): void
    {
        for ($attempt = 0; ; $attempt++) {
            $result = $connection->execute($command, sudo: true, timeout: $timeout);

            if ($result->successful()) {
                return;
            }

            if ($attempt >= self::DOWNLOAD_RETRIES || !$this->downloadFailed($result->output)) {
                $result->throw();
            }

            Sleep::for(10)->seconds();
        }
    }

    private function refreshIndex(SSHConnection $connection, Server $server, string $command): void
    {
        $locks = 0;
        $blips = 0;

        while (true) {
            $result = $connection->execute($command, sudo: true, timeout: SSHTransport::PACKAGE_SECONDS);

            if ($result->successful()) {
                return;
            }

            $retry = Str::contains($result->output, self::INDEX_LOCKED)
                ? $locks++ < self::INDEX_LOCK_RETRIES
                : $this->downloadFailed($result->output) && $blips++ < self::DOWNLOAD_RETRIES;

            if (!$retry) {
                $failure = new SSHCommandFailedException($result->output, $result->exitCode);
                $source = AptPackageManager::failedSource($result->output);

                throw $source === null ? $failure : PackageSourceFailed::refreshing($server, $source, $failure);
            }

            Sleep::for(10)->seconds();
        }
    }

    private function downloadFailed(string $output): bool
    {
        foreach (self::DOWNLOAD_ERRORS as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function uninstall(SSHConnection $connection, string $runtime, string $version): void
    {
        self::assertValid($runtime, $version);

        $command = in_array($runtime, self::PLUGINS, true)
            ? $this->asdfCommand($connection, 'uninstall', $runtime, $version)
            : 'rm -rf -- ' . escapeshellarg($this->versionPath($runtime, $version));

        $connection->execute($command, sudo: true)->throw();
    }

    /**
     * @param  array<string, string>  $env
     */
    private function asdfCommand(SSHConnection $connection, string $action, string $runtime, string $version, array $env = []): string
    {
        return $this->asRuntimeUser($connection, "asdf {$action} \"\$CORESMITH_RUNTIME\" \"\$CORESMITH_VERSION\"", [
            ...$env,
            'CORESMITH_RUNTIME' => $runtime,
            'CORESMITH_VERSION' => $version,
        ]);
    }

    /**
     * @return list<string>
     */
    public function installedVersions(SSHConnection $connection, string $runtime): array
    {
        $path = escapeshellarg(self::INSTALL_DIR . '/' . $runtime);
        $result = $connection->execute("ls -1 {$path} 2>/dev/null || true");

        $versions = array_values(array_filter(
            array_map(trim(...), preg_split('/\r\n|\n|\r/', $result->output) ?: []),
            fn(string $version): bool => preg_match('/^\d[0-9A-Za-z.+-]*$/D', $version) === 1 && self::isValid($runtime, $version),
        ));

        sort($versions);

        return $versions;
    }

    private static function isValid(string $runtime, string $version): bool
    {
        try {
            self::assertValid($runtime, $version);
        } catch (InvalidRuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $inUse
     * @return list<string>
     */
    public function orphanedVersions(SSHConnection $connection, string $runtime, array $inUse): array
    {
        return array_values(array_diff($this->installedVersions($connection, $runtime), $inUse));
    }

    public function binaryPath(string $runtime, string $version, ?string $binary = null): string
    {
        $binary ??= $runtime === 'nodejs' ? 'node' : $runtime;

        return $this->versionPath($runtime, $version) . '/bin/' . $binary;
    }

    public function fpmBinaryPath(string $phpVersion): string
    {
        return $this->versionPath('php', $phpVersion) . '/sbin/php-fpm';
    }

    public function versionPath(string $runtime, string $version): string
    {
        if ($version !== '') {
            self::assertValid($runtime, $version);
        }

        return self::INSTALL_DIR . "/{$runtime}/{$version}";
    }

    /**
     * @param  array<string, string>  $env
     */
    public function asRuntimeUser(SSHConnection $connection, string $command, array $env = []): string
    {
        return self::asSystemUser($command, $env, $connection->server()->runtimeUser());
    }

    /**
     * @param  array<string, string>  $env
     */
    public static function asSystemUser(string $command, array $env = [], string $user = self::SYSTEM_USER): string
    {
        $user = SafeName::assertSystemUser($user);
        $variables = [
            'HOME' => '/home/' . $user,
            'ASDF_DIR' => AsdfInstaller::ASDF_DIR,
            'ASDF_DATA_DIR' => self::DATA_DIR,
            'PATH' => self::PATH,
            ...$env,
        ];

        $exports = '';

        foreach ($variables as $name => $value) {
            $exports .= $name . '=' . escapeshellarg($value) . ' ';
        }

        return 'sudo -u ' . $user . ' env ' . $exports . 'bash -c ' . escapeshellarg('cd "$HOME" && ' . $command);
    }
}
