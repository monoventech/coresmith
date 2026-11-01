<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Tasks;

use App\Enums\CloudFailure;
use App\Enums\ServerStatus;
use App\Exceptions\CloudProviderException;
use App\Exceptions\HostKeyChangedException;
use App\Jobs\AuditServer;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Deployment\LogSanitizer;
use App\Services\Server\ProvisioningLog;
use App\Services\SSH\SSHConnectionManager;
use Throwable;

final class CreateServerTask implements CloudTaskHandler
{
    public const STEPS = ['create', 'boot', 'ssh', 'settle'];

    public const POLL_SECONDS = 5;

    public const SETTLE_SECONDS = 10;

    public const UNCERTAIN_LIMIT = 2;

    public const SETTLE_PROBE = "{ command -v cloud-init >/dev/null 2>&1 && cloud-init status 2>/dev/null | head -n 1 || echo 'status: absent'; }; pgrep -x 'apt|apt-get|dpkg|unattended-upgr' >/dev/null && echo 'apt: busy' || echo 'apt: idle'";

    private const UNCERTAIN_SECONDS = 15;

    private const SSH_SECONDS = 15;

    private const ERROR_LENGTH = 160;

    private const CUT = '...';

    private const CLOUD_INIT_STATE = '/status:\s*([a-z ]+)/D';

    private const SETTLING = ['running', 'not started'];

    public function __construct(
        private readonly SSHConnectionManager $connections,
        private readonly LogSanitizer $sanitizer,
    ) {}

    public static function tag(Server $server): string
    {
        return 'coresmith-' . strtolower((string) $server->getKey());
    }

    public static function keyName(Server $server): string
    {
        return 'coresmith-' . $server->name . '-' . strtolower(substr((string) $server->getKey(), -6));
    }

    public function step(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        return match ($task->step) {
            'create' => $this->create($server, $task, $api),
            'boot' => $this->boot($server, $task, $api),
            'ssh' => $this->ssh($server),
            'settle' => $this->settle($server),
        };
    }

    public function expired(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $label = $api->type()->label();

        return match ($task->step) {
            'create' => $this->createExpired($server, $task, $api, $label),
            'boot' => StepResult::fail("{$label} didn't finish starting {$server->name} within 10 minutes. It may still be starting: try again to keep waiting, or destroy it."),
            'ssh' => StepResult::fail("{$server->name} is running at {$server->ip_address}, but Coresmith couldn't sign in over SSH within 10 minutes." . self::lastError($task->data['last_error'] ?? null) . ' Try again to keep waiting, or destroy it and provision another.'),
            'settle' => $this->carryOn($server),
        };
    }

    public function finished(Server $server, CloudTask $task, CloudApi $api): void
    {
        $server->forceFill(['status' => ServerStatus::Pending])->save();

        $label = $api->type()->label();

        activity()
            ->performedOn($server)
            ->causedBy($task->by === null ? null : User::query()->find($task->by))
            ->withProperties([
                'provider' => $label,
                'size' => $server->provider_size,
                'region' => $server->region,
                'seconds' => $task->startedAt === null ? null : max(0, (int) $task->startedAt->diffInSeconds(now())),
            ])
            ->log('server_created_at_provider');

        AuditServer::begin($server, provisionAfter: true);

        if (!$this->removeKey($server, $task, $api)) {
            ProvisioningLog::append($server, "couldn't remove the uploaded key from {$label}, delete " . self::keyName($server) . ' there');
        }
    }

    public function failed(Server $server, CloudTask $task, ?CloudApi $api, string $reason): void
    {
        ProvisioningLog::append($server, "failed: {$reason}");

        activity()
            ->performedOn($server)
            ->withProperties(['provider' => self::label($server, $api), 'step' => $task->step, 'reason' => $reason])
            ->log('server_creation_failed');

        $this->removeKey($server, $task, $api);
    }

    private function create(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        if (filled($server->provider_server_id)) {
            return StepResult::next('boot');
        }

        $label = $api->type()->label();
        $found = $api->findTagged(self::tag($server));

        if ($found !== null) {
            return $this->adopt($server, $found, $label);
        }

        $keyId = self::keyId($task->data);

        if ($keyId === null && $api->uploadsKeys()) {
            $keyId = $api->uploadKey(self::keyName($server), (string) $server->ssh_public_key);
            CloudTask::touch($server, ['key_id' => $keyId]);
        }

        try {
            $instance = $api->create(new CloudServerSpec(
                $server->name,
                (string) $server->region,
                (string) $server->provider_size,
                (string) $server->provider_image,
                self::tag($server),
                (string) $server->ssh_public_key,
                $keyId,
            ));
        } catch (CloudProviderException $e) {
            return $this->unconfirmed($server, $task, $label, $e);
        }

        $server->forceFill(['provider_server_id' => $instance->id])->save();
        ProvisioningLog::append($server, "{$label} accepted it as server {$instance->id}, waiting for it to boot");

        return StepResult::next('boot', ['uncertain' => 0]);
    }

    private function unconfirmed(Server $server, CloudTask $task, string $label, CloudProviderException $e): StepResult
    {
        if ($e->failure === CloudFailure::Refused) {
            return StepResult::fail($e->getMessage() . ' Remove this server and provision it again with another size or region.');
        }

        if (!$e->retryable()) {
            throw $e;
        }

        $uncertain = (int) ($task->data['uncertain'] ?? 0) + 1;

        if ($uncertain <= self::UNCERTAIN_LIMIT) {
            return StepResult::wait(self::UNCERTAIN_SECONDS, ['uncertain' => $uncertain]);
        }

        CloudTask::touch($server, ['uncertain' => $uncertain]);

        return StepResult::fail(self::unanswered($server, $label));
    }

    private function createExpired(Server $server, CloudTask $task, CloudApi $api, string $label): StepResult
    {
        if (filled($server->provider_server_id)) {
            return StepResult::next('boot');
        }

        $lastError = $task->data['last_error'] ?? null;

        try {
            $found = $api->findTagged(self::tag($server));
        } catch (CloudProviderException $e) {
            $found = null;
            $lastError = $e->getMessage();
            CloudTask::touch($server, ['last_error' => $lastError]);
        }

        if ($found !== null) {
            return $this->adopt($server, $found, $label);
        }

        if ((int) ($task->data['uncertain'] ?? 0) > 0) {
            return StepResult::fail(self::unanswered($server, $label));
        }

        return StepResult::fail("{$label} kept failing for 15 minutes before Coresmith could ask it to create {$server->name}." . self::lastError($lastError) . ' Try again.');
    }

    private function adopt(Server $server, CloudInstance $instance, string $label): StepResult
    {
        $server->forceFill(['provider_server_id' => $instance->id])->save();
        ProvisioningLog::append($server, "found {$label} server {$instance->id} from an earlier attempt, using it");

        return StepResult::next('boot');
    }

    private function boot(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $id = (string) $server->provider_server_id;
        $instance = $api->find($id);

        if ($instance === null) {
            return ($task->data['missing'] ?? false) === true
                ? StepResult::fail("{$api->type()->label()} says server {$id} no longer exists. Remove this server and provision it again.")
                : StepResult::wait(self::POLL_SECONDS, ['missing' => true]);
        }

        if (!$instance->isReady()) {
            return StepResult::wait(self::POLL_SECONDS);
        }

        $server->forceFill(['ip_address' => $instance->ipv4])->save();
        ProvisioningLog::append($server, "running at {$instance->ipv4}, waiting for SSH");

        return StepResult::next('ssh');
    }

    private function ssh(Server $server): StepResult
    {
        try {
            $this->connections->disconnect($server);
            $this->connections->for($server)->execute('true', timeout: self::SSH_SECONDS)->throw();
        } catch (HostKeyChangedException) {
            return StepResult::fail("{$server->name} presented a different SSH host key while it was starting. Review it on this page before trying again.");
        } catch (Throwable $e) {
            return StepResult::wait(self::POLL_SECONDS, ['last_error' => self::cut($this->sanitizer->sanitize($e->getMessage()))]);
        } finally {
            $this->connections->disconnect($server);
        }

        $server->forceFill(['last_connected_at' => now()])->save();
        ProvisioningLog::append($server, 'signed in over SSH, waiting for first-boot setup to finish');

        return StepResult::next('settle');
    }

    private function settle(Server $server): StepResult
    {
        try {
            $this->connections->disconnect($server);
            $output = $this->connections->for($server)->execute(self::SETTLE_PROBE, timeout: self::SSH_SECONDS)->output;
        } catch (Throwable) {
            return StepResult::wait(self::SETTLE_SECONDS);
        } finally {
            $this->connections->disconnect($server);
        }

        if (self::settling($output)) {
            return StepResult::wait(self::SETTLE_SECONDS);
        }

        ProvisioningLog::append($server, 'first-boot setup finished, checking the server before provisioning');

        return StepResult::done();
    }

    private function carryOn(Server $server): StepResult
    {
        ProvisioningLog::append($server, 'first-boot setup was still running after 15 minutes, carrying on');

        return StepResult::done();
    }

    private function removeKey(Server $server, CloudTask $task, ?CloudApi $api): bool
    {
        $keyId = self::keyId($task->data);

        if ($keyId === null) {
            return true;
        }

        if ($api === null) {
            return false;
        }

        try {
            $api->deleteKey($keyId);
        } catch (CloudProviderException) {
            return false;
        }

        $current = $server->fresh();

        if ($current !== null && self::keyId(CloudTask::of($current)?->data ?? []) === $keyId) {
            CloudTask::touch($current, ['key_id' => null]);
        }

        return true;
    }

    private static function settling(string $output): bool
    {
        $state = preg_match(self::CLOUD_INIT_STATE, $output, $match) === 1 ? trim($match[1]) : null;

        return in_array($state, self::SETTLING, true) || str_contains($output, 'apt: busy');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function keyId(array $data): ?string
    {
        $keyId = $data['key_id'] ?? null;

        return is_string($keyId) && $keyId !== '' ? $keyId : null;
    }

    private static function label(Server $server, ?CloudApi $api): string
    {
        return ($api?->type() ?? $server->cloudProvider?->type)?->label() ?? 'the provider';
    }

    private static function cut(string $message): string
    {
        if (mb_strlen($message) <= self::ERROR_LENGTH) {
            return $message;
        }

        return rtrim(mb_substr($message, 0, self::ERROR_LENGTH - mb_strlen(self::CUT))) . self::CUT;
    }

    public static function lastError(mixed $error): string
    {
        $error = is_string($error) ? trim($error) : '';

        if (!str_ends_with($error, self::CUT)) {
            $error = rtrim($error, '. ');
            $error = $error === '' ? '' : "{$error}.";
        }

        return $error === '' ? '' : " The last error was: {$error}";
    }

    private static function unanswered(Server $server, string $label): string
    {
        return "Coresmith couldn't get an answer from {$label} after three tries. Check the {$label} console for a server called {$server->name}: if there is one, delete it there, then remove this server and provision it again.";
    }
}
