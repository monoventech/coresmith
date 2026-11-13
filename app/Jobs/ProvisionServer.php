<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Events\ServerProvisioned;
use App\Events\ServerProvisioningFailed;
use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Deployment\LogSanitizer;
use App\Services\Panel\ServerPresenter;
use App\Services\Runtime\AsdfInstaller;
use App\Services\Server\BasePackages;
use App\Services\Server\DirectoryStructure;
use App\Services\Server\FirewallConfigurator;
use App\Services\Server\HardwareFacts;
use App\Services\Server\OsDetector;
use App\Services\Server\PackageManagerFactory;
use App\Services\Server\ProvisioningLog;
use App\Services\Server\ProvisioningVerifier;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProvisionServer implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        private readonly Server $server,
        private readonly bool $fromScratch = false,
    ) {
        $this->onQueue('provisioning');
    }

    public static function begin(Server $server): void
    {
        $waiting = $server->status;

        if (!$waiting->isBeforeProvisioning() || !self::move($server, $waiting, ServerStatus::Provisioning)) {
            return;
        }

        try {
            self::dispatch($server);
        } catch (Throwable $e) {
            self::move($server, ServerStatus::Provisioning, $waiting);

            throw $e;
        }
    }

    public static function again(Server $server, bool $fromScratch = false): bool
    {
        if (!self::move($server, ServerStatus::Failed, ServerStatus::Provisioning)) {
            return false;
        }

        $server->forceFill(['provisioning_error' => null, ...($fromScratch ? ['provisioning_log' => null] : [])])->save();

        try {
            self::dispatch($server, fromScratch: $fromScratch);
        } catch (Throwable $e) {
            self::move($server, ServerStatus::Provisioning, ServerStatus::Failed);

            throw $e;
        }

        return true;
    }

    public function handle(SSHConnectionManager $connections, OsDetector $detector): void
    {
        $connection = $connections->for($this->server);

        $this->server->forceFill([
            'provisioning_error' => null,
            ...($this->fromScratch ? ['provisioning_phase' => 0] : []),
        ])->save();

        $this->server->forceFill(['status' => ServerStatus::Provisioning])->save();

        $startedAt = now();

        try {
            $this->phase(1, fn() => $this->detectOperatingSystem($connection, $detector));
            $this->phase(2, fn() => $this->installBasePackages($connection));
            $this->phase(3, fn() => app(AsdfInstaller::class)->install($connection));
            $this->phase(4, fn() => app(DirectoryStructure::class)->create($connection));
            $this->phase(5, fn() => app(CaddyInstaller::class)->install($connection));
            $this->phase(6, fn() => app(FirewallConfigurator::class)->configure($connection));
            $this->phase(7, fn() => app(SecurityHardening::class)->harden($connection));
            $this->phase(8, fn() => $this->finalize($connection, (int) $startedAt->diffInSeconds(now())));
        } catch (Throwable $e) {
            $this->fail_($e);

            throw $e;
        } finally {
            $connections->disconnect($this->server);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($this->server->fresh()?->status === ServerStatus::Failed) {
            return;
        }

        $this->fail_($e);
    }

    private function finalize(SSHConnection $connection, int $durationSeconds): void
    {
        app(ProvisioningVerifier::class)->verify($connection);

        $this->server->forceFill([
            'status' => ServerStatus::Active,
            'last_connected_at' => now(),
        ])->save();

        activity()
            ->performedOn($this->server)
            ->withProperties(['duration_seconds' => $durationSeconds])
            ->log('server_provisioned');

        ServerProvisioned::dispatch($this->server, $durationSeconds);
        CheckProtections::dispatch($this->server)->delay(now()->addSeconds(30));
    }

    private function detectOperatingSystem(SSHConnection $connection, OsDetector $detector): void
    {
        $info = $detector->detect($connection);
        $facts = app(HardwareFacts::class);

        $this->server->forceFill([
            'os_name' => $info->name,
            'os_version' => $info->version,
            'meta' => [...$info->toMeta(), ...$facts->toMeta($facts->read($connection))],
        ])->save();
    }

    private function installBasePackages(SSHConnection $connection): void
    {
        $server = $this->server->fresh();
        $packages = PackageManagerFactory::for($server);
        $repositories = BasePackages::repositories($server);

        if ($repositories !== null) {
            $connection->execute($repositories, sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
        }

        $connection->execute($packages->update(), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
        $connection->execute($packages->install(BasePackages::for($server)), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
    }

    private function phase(int $number, Closure $work): void
    {
        $name = ServerPresenter::PROVISIONING_PHASES[$number - 1]['name'];

        if (($this->server->provisioning_phase ?? 0) >= $number) {
            $this->log("phase {$number} of 8, {$name}: already done, skipped");

            return;
        }

        $this->log("phase {$number} of 8, {$name}: started");

        $work();

        $this->server->forceFill(['provisioning_phase' => $number])->save();

        $this->log("phase {$number} of 8, {$name}: done");
    }

    private function fail_(Throwable $e): void
    {
        $sanitizer = app(LogSanitizer::class);
        $number = (int) ($this->server->provisioning_phase ?? 0) + 1;
        $name = ServerPresenter::PROVISIONING_PHASES[$number - 1]['name'] ?? 'Provisioning';
        $message = $sanitizer->sanitize($e->getMessage());
        $output = $e instanceof SSHCommandFailedException ? $sanitizer->sanitize($this->tail($e->output())) : '';
        $reason = $e instanceof SSHCommandFailedException ? "The command exited with code {$e->exitCode()}." : $message;

        $this->log("phase {$number} of 8, {$name}: failed. " . $message);

        if ($output !== '') {
            $this->log($output, stamped: false);
        }

        $this->server->forceFill([
            'status' => ServerStatus::Failed,
            'provisioning_error' => trim("{$name} failed. {$reason}\n{$output}"),
        ])->save();

        activity()
            ->performedOn($this->server)
            ->withProperties(['phase' => $number, 'name' => $name])
            ->log('provisioning_failed');

        ServerProvisioningFailed::dispatch($this->server, $number, $name);
    }

    private function log(string $line, bool $stamped = true): void
    {
        ProvisioningLog::append($this->server, $line, $stamped);
    }

    private static function move(Server $server, ServerStatus $from, ServerStatus $to): bool
    {
        $moved = Server::query()->whereKey($server->getKey())->where('status', $from->value)->update(['status' => $to->value]) === 1;

        if ($moved) {
            $server->forceFill(['status' => $to])->syncOriginalAttribute('status');
        }

        return $moved;
    }

    private function tail(string $output): string
    {
        $lines = array_values(array_filter(array_map(rtrim(...), preg_split('/\r\n|\n|\r/', $output) ?: []), fn(string $line): bool => trim($line) !== ''));

        return implode("\n", array_slice($lines, -12));
    }
}
