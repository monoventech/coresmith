<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Enums\PowerState;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\LogSanitizer;
use App\Services\Deployment\ReleasePaths;
use App\Services\Php\PhpLimits;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

final class ProcessManager
{
    public const START_GRACE_SECONDS = 3;

    public const START_LOG_LINES = 200;

    private const NAMED_ERROR = '/\b[A-Z][A-Za-z0-9_]*(?:Error|Exception)\b|\bErrno::E[A-Z]+\b|(?i:cannot load such file|fatal error|fatal:|permission denied|address already in use)/';

    private const ERROR_WORD = '/error|exception|cannot|failed|fatal|not found|denied/i';

    private const REQUEST_LINE = '/\b(?:Started (?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS) "|Processing by |Completed \d{3} )/';

    private const FRAME_LINE = '/^\s+at |^#\d+ |^\s+File "|^\s+from |:\d+:in [`\'][^`\']*\'\s*$/';

    private const ERROR_LINES = 15;

    private const END_LINES = 10;

    private const FALLBACK_LINES = 30;

    private const EXCERPT_LINES = 60;

    public function __construct(
        private readonly SystemdUnitBuilder $units,
        private readonly FpmPoolBuilder $fpm,
        private readonly ProcessStartAdvice $advice = new ProcessStartAdvice,
        private readonly LogSanitizer $sanitizer = new LogSanitizer,
        private readonly AppAccount $account = new AppAccount,
        private readonly LogRotateBuilder $rotation = new LogRotateBuilder,
    ) {}

    public function install(SSHConnection $connection, Application $application): void
    {
        $connection->execute('mkdir -p ' . (new ReleasePaths($application))->logs(), sudo: true)->throw();

        if ($application->type->language()->usesFpm()) {
            $connection->execute($this->account->shareStorage($application), sudo: true)->throw();
            $connection->execute('mkdir -p /etc/php-fpm.d /var/run/php', sudo: true)->throw();
            $connection->uploadContent($this->fpm->pool($application), $this->fpm->poolPath($application));
            $connection->uploadContent(
                $this->fpm->unit($application),
                SystemdUnitBuilder::UNIT_DIR . '/' . $this->fpm->unitName($application) . '.service',
            );
            $ini = PhpLimits::directory($application) . '/' . PhpLimits::FILE;
            $connection->execute('install -d -m 0755 ' . escapeshellarg(PhpLimits::DIRECTORY) . ' ' . escapeshellarg(PhpLimits::directory($application)), sudo: true)->throw();
            $connection->uploadContent(PhpLimits::for($application)->ini(), $ini);
            $connection->execute('chmod 0644 ' . escapeshellarg($ini), sudo: true)->throw();
        }

        $webCommand = $this->units->webCommand($application);
        $logged = [];

        if ($webCommand !== null) {
            $connection->uploadContent(
                $this->units->build($application, 'web', $webCommand),
                $this->units->unitPath($application, 'web'),
            );
            $logged[] = 'web';
        } else {
            $path = escapeshellarg($this->units->unitPath($application, 'web'));
            $connection->execute("test ! -f {$path} || { systemctl disable --now " . escapeshellarg($this->units->unitName($application, 'web')) . " || true; rm -f {$path}; }", sudo: true);
        }

        foreach ($this->workerProcesses($application) as $name => $command) {
            $connection->uploadContent(
                $this->units->build($application, $name, $command),
                $this->units->unitPath($application, $name),
            );
            $logged[] = $name;
        }

        $connection->execute('systemctl daemon-reload', sudo: true)->throw();

        $this->rotation->write($connection, $application, $logged);

        if ($application->type->language()->usesFpm()) {
            $application->forceFill(['php_written_at' => now()])->save();
        }
    }

    public function start(SSHConnection $connection, Application $application): void
    {
        foreach ($this->unitNames($application) as $unit) {
            $connection->execute('systemctl enable --now ' . escapeshellarg($unit), sudo: true)->throw();
        }

        $application->forceFill(['power_state' => PowerState::Active])->save();
    }

    public function stop(SSHConnection $connection, Application $application, PowerState $state = PowerState::Stopped): void
    {
        foreach ($this->unitNames($application) as $unit) {
            $connection->execute('systemctl stop ' . escapeshellarg($unit), sudo: true)->throw();
        }

        $application->forceFill(['power_state' => $state])->save();
    }

    public function uninstall(SSHConnection $connection, Application $application): void
    {
        foreach ($this->unitNames($application) as $unit) {
            $connection->execute('systemctl disable --now ' . escapeshellarg($unit) . ' || true', sudo: true);
            $connection->execute('rm -f ' . escapeshellarg(SystemdUnitBuilder::UNIT_DIR . '/' . $unit . '.service'), sudo: true)->throw();
        }

        if ($application->type->language()->usesFpm()) {
            $connection->execute('rm -f ' . escapeshellarg($this->fpm->poolPath($application)), sudo: true)->throw();
            $connection->execute('rm -rf ' . escapeshellarg(PhpLimits::directory($application)), sudo: true)->throw();
        }

        $connection->execute('systemctl daemon-reload', sudo: true)->throw();
        $this->rotation->remove($connection, $application);

        $application->forceFill(['power_state' => PowerState::Stopped])->save();
    }

    /**
     * @param  list<string>  $names
     */
    public function removeUnits(SSHConnection $connection, Application $application, array $names): void
    {
        if ($names === []) {
            return;
        }

        foreach ($names as $name) {
            $connection->execute('systemctl disable --now ' . escapeshellarg($this->units->unitName($application, $name)) . ' || true', sudo: true);
            $connection->execute('rm -f ' . escapeshellarg($this->units->unitPath($application, $name)), sudo: true);
        }

        $connection->execute('systemctl daemon-reload', sudo: true);
    }

    public function refresh(SSHConnection $connection, Application $application, bool $restart = false): void
    {
        $fpm = $application->type->language()->usesFpm() ? $this->fpm->unitName($application) : null;

        foreach ($this->unitNames($application) as $unit) {
            $connection->execute('systemctl enable ' . escapeshellarg($unit), sudo: true)->throw();
            $connection->execute('systemctl ' . ($unit === $fpm && !$restart ? 'reload-or-restart ' : 'restart ') . escapeshellarg($unit), sudo: true);
        }
    }

    /**
     * @param  list<string>|null  $only
     */
    public function verifyStarted(SSHConnection $connection, Application $application, ?array $only = null): void
    {
        $units = $only ?? $this->unitNames($application);

        if ($units === []) {
            return;
        }

        Sleep::for(self::START_GRACE_SECONDS)->seconds();

        $fpm = $application->type->language()->usesFpm() ? $this->fpm->unitName($application) : null;

        foreach ($units as $unit) {
            $state = trim($connection->execute('systemctl is-active ' . escapeshellarg($unit))->output);

            if ($state === 'active') {
                continue;
            }

            $journal = $connection->execute(
                'journalctl -u ' . escapeshellarg($unit) . " --no-pager -n 30 --since '2 minutes ago' 2>/dev/null || true",
                sudo: true,
            )->output;
            $log = $unit === $fpm ? '' : trim($this->excerpt($this->sanitizer->sanitize($this->processLog($connection, $application, $unit, self::START_LOG_LINES), $application)));
            $output = $log === '' ? $journal : trim(rtrim($journal) . "\n" . $log);

            throw new ProcessDidNotStart($unit, $state === '' ? 'unknown' : $state, $output, $this->advice->for($application, $output));
        }
    }

    /**
     * @return list<string>
     */
    public function processNames(Application $application): array
    {
        return array_map(fn(string $unit): string => ($process = $this->process($application, $unit)) === 'fpm' ? 'php' : $process, $this->unitNames($application));
    }

    public function restart(SSHConnection $connection, Application $application, string $process): void
    {
        $unit = $process === 'php' ? $this->fpm->unitName($application) : $this->units->unitName($application, $process);

        if (!in_array($unit, $this->unitNames($application), true)) {
            throw new RuntimeException("{$application->name} runs no {$process} process.");
        }

        $connection->execute('systemctl restart ' . escapeshellarg($unit), sudo: true)->throw();
    }

    public function serves(Application $application, string $unit): bool
    {
        return ($application->type->language()->usesFpm() && $unit === $this->fpm->unitName($application))
            || ($this->units->webCommand($application) !== null && $unit === $this->units->unitName($application, 'web'));
    }

    public function process(Application $application, string $unit): string
    {
        return SafeName::assertProcess(Str::after($unit, 'coresmith-app-' . SafeName::assertSlug($application->slug) . '-'));
    }

    public function processLog(SSHConnection $connection, Application $application, string $unit, int $lines): string
    {
        $log = escapeshellarg((new ReleasePaths($application))->processLog($this->process($application, $unit)));

        return $connection->execute("tail -n {$lines} {$log} 2>/dev/null || true", sudo: true)->output;
    }

    public function sleep(SSHConnection $connection, Application $application): void
    {
        $this->stop($connection, $application, PowerState::Sleeping);
    }

    public function wake(SSHConnection $connection, Application $application): void
    {
        $this->start($connection, $application);

        $application->forceFill(['last_request_at' => now()])->save();
    }

    /**
     * @return list<string>
     */
    public function unitNames(Application $application): array
    {
        $names = [];

        if ($application->type->language()->usesFpm()) {
            $names[] = $this->fpm->unitName($application);
        }

        if ($this->units->webCommand($application) !== null) {
            $names[] = $this->units->unitName($application, 'web');
        }

        foreach (array_keys($this->workerProcesses($application)) as $process) {
            $names[] = $this->units->unitName($application, $process);
        }

        return $names;
    }

    private function excerpt(string $log): string
    {
        $lines = preg_split('/\r\n|\n|\r/', rtrim($log, "\r\n")) ?: [];
        $candidates = preg_grep(self::REQUEST_LINE, $lines, PREG_GREP_INVERT) ?: [];
        $candidates = preg_grep(self::FRAME_LINE, $candidates, PREG_GREP_INVERT) ?: [];
        $error = array_key_last(preg_grep(self::NAMED_ERROR, $candidates) ?: []) ?? array_key_last(preg_grep(self::ERROR_WORD, $candidates) ?: []);

        $excerpt = match (true) {
            $error === null => array_slice($lines, -self::FALLBACK_LINES),
            count($lines) - self::END_LINES > $error + self::ERROR_LINES => [...array_slice($lines, $error, self::ERROR_LINES), '...', ...array_slice($lines, -self::END_LINES)],
            default => array_slice($lines, $error),
        };

        return implode("\n", array_slice($excerpt, 0, self::EXCERPT_LINES));
    }

    /**
     * @return array<string, string>
     */
    private function workerProcesses(Application $application): array
    {
        $processes = [];

        foreach ((array) $application->processes as $process) {
            if (!is_array($process) || !isset($process['name'], $process['command'])) {
                continue;
            }

            $processes[SafeName::assertProcess((string) $process['name'])] = (string) $process['command'];
        }

        return $processes;
    }
}
