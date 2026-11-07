<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Tasks;

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Exceptions\CloudProviderException;
use App\Exceptions\HostKeyChangedException;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Server\OsDetector;
use App\Services\Server\ProvisioningLog;
use App\Services\SSH\SSHConnectionManager;
use Throwable;

final class ResizeServerTask implements CloudTaskHandler
{
    public const STEPS = ['power_off', 'wait_off', 'change', 'wait_change', 'power_on', 'wait_on', 'reconnect', 'recover'];

    private const POLL_SECONDS = 5;

    private const CHANGE_POLL_SECONDS = 10;

    private const SSH_SECONDS = 15;

    private const ON = [CloudInstanceState::Running, CloudInstanceState::Starting];

    public function __construct(
        private readonly SSHConnectionManager $connections,
        private readonly OsDetector $detector,
        private readonly CloudCatalog $catalog,
    ) {}

    public function step(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        return match ($task->step) {
            'power_off' => $this->powerOff($server, $task, $api),
            'wait_off' => $this->waitOff($server, $api),
            'change' => $this->change($server, $task, $api),
            'wait_change' => $this->waitChange($server, $task, $api),
            'power_on' => $this->powerOn($server, $task, $api),
            'wait_on' => $this->waitOn($server, $api),
            'reconnect' => $this->reconnect($server, $task, $api),
            'recover' => $this->recover($server, $task, $api),
        };
    }

    public function expired(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $label = $api->type()->label();
        $to = self::to($task);

        return match ($task->step) {
            'wait_off' => StepResult::next('recover', ['reason' => "{$label} didn't shut {$server->name} down within 5 minutes."]),
            'change' => self::powerCycle($task) ? $this->changeExpired($server, $task, $api) : $this->step($server, $task, $api),
            'wait_change' => self::powerCycle($task)
                ? StepResult::next('recover', ['reason' => "{$label} didn't finish resizing {$server->name} within 20 minutes."])
                : StepResult::fail("{$label} didn't finish resizing {$server->name} within 20 minutes. Check it in the {$label} console."),
            'power_on' => $this->powerOnExpired($server, $task, $api),
            'wait_on' => StepResult::fail("{$server->name} is resized to {$to} but didn't start again within 5 minutes. Start it from the {$label} console."),
            'reconnect' => StepResult::fail("{$server->name} is resized to {$to} and running at {$label}, but Coresmith couldn't sign in over SSH within 10 minutes. Check it in the {$label} console."),
            'recover' => $this->recover($server, $task, $api),
            default => $this->step($server, $task, $api),
        };
    }

    public function finished(Server $server, CloudTask $task, CloudApi $api): void
    {
        $from = self::from($task);
        $to = self::to($task);

        ProvisioningLog::append($server, "resized from {$from} to {$to}");

        activity()
            ->performedOn($server)
            ->causedBy($task->by === null ? null : User::query()->find($task->by))
            ->withProperties(['from' => $from, 'to' => $to, 'provider' => $api->type()->label()])
            ->log('server_resized');

        SyncAuthorizedKeys::dispatch($server);
    }

    public function failed(Server $server, CloudTask $task, ?CloudApi $api, string $reason): void
    {
        activity()
            ->performedOn($server)
            ->causedBy($task->by === null ? null : User::query()->find($task->by))
            ->withProperties(['from' => self::from($task), 'to' => self::to($task), 'reason' => $reason])
            ->log('server_resize_failed');

        SyncAuthorizedKeys::dispatch($server);
    }

    private function powerOff(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $instance = $api->find(self::id($server));

        if ($instance === null) {
            return StepResult::fail(self::gone($server, $api));
        }

        if ($instance->state === CloudInstanceState::Stopped) {
            return StepResult::next('change', ['powered_off' => true]);
        }

        try {
            $api->powerOff(self::id($server));
        } catch (CloudProviderException $e) {
            if ($e->failure === CloudFailure::Refused) {
                return StepResult::fail($e->getMessage() . " Nothing changed, {$server->name} is still on " . self::from($task) . '.');
            }

            throw $e;
        }

        return StepResult::next('wait_off', ['powered_off' => true]);
    }

    private function waitOff(Server $server, CloudApi $api): StepResult
    {
        $instance = $api->find(self::id($server));

        if ($instance === null) {
            return StepResult::fail(self::gone($server, $api));
        }

        return $instance->state === CloudInstanceState::Stopped ? StepResult::next('change') : StepResult::wait(self::POLL_SECONDS);
    }

    private function change(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        try {
            $instance = $api->find(self::id($server));

            if ($instance === null) {
                return StepResult::fail(self::gone($server, $api));
            }

            if ($instance->size === self::to($task)) {
                return StepResult::next('wait_change');
            }

            $api->resize(self::id($server), self::to($task));
        } catch (CloudProviderException $e) {
            if (!self::powerCycle($task)) {
                if ($e->failure === CloudFailure::Refused) {
                    return StepResult::fail($e->getMessage() . ' Nothing changed.');
                }

                throw $e;
            }

            if ($e->retryable()) {
                throw $e;
            }

            return StepResult::next('recover', ['reason' => $e->getMessage()]);
        }

        return StepResult::next('wait_change');
    }

    private function changeExpired(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        try {
            if ($api->find(self::id($server))?->size === self::to($task)) {
                return StepResult::next('wait_change');
            }
        } catch (CloudProviderException) {
        }

        return StepResult::next('recover', ['reason' => self::keptFailing($task, $api, "resize {$server->name}")]);
    }

    private function waitChange(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $instance = $api->find(self::id($server));
        $settled = self::powerCycle($task) ? CloudInstanceState::Stopped : CloudInstanceState::Running;

        if ($instance !== null && $instance->size === self::to($task)) {
            $this->recordSize($server, self::to($task), $api->type()->label());

            if ($instance->state === $settled) {
                return StepResult::next(self::powerCycle($task) ? 'power_on' : 'reconnect');
            }
        }

        return StepResult::wait(self::CHANGE_POLL_SECONDS);
    }

    private function powerOn(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        try {
            $instance = $api->find(self::id($server));

            if ($instance === null) {
                return StepResult::fail(self::gone($server, $api));
            }

            if (in_array($instance->state, self::ON, true)) {
                return StepResult::next('wait_on');
            }

            $api->powerOn(self::id($server));
        } catch (CloudProviderException $e) {
            if ($e->retryable()) {
                throw $e;
            }

            self::noteRefusedToken($server, $e);

            return StepResult::fail($e->getMessage() . ' ' . self::stillOff($server, $task, $api));
        }

        return StepResult::next('wait_on');
    }

    private function powerOnExpired(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        try {
            if (in_array($api->find(self::id($server))?->state, self::ON, true)) {
                return StepResult::next('wait_on');
            }
        } catch (CloudProviderException) {
        }

        return StepResult::fail(self::keptFailing($task, $api, "start {$server->name} again") . ' ' . self::stillOff($server, $task, $api));
    }

    private function waitOn(Server $server, CloudApi $api): StepResult
    {
        return $api->find(self::id($server))?->state === CloudInstanceState::Running
            ? StepResult::next('reconnect')
            : StepResult::wait(self::POLL_SECONDS);
    }

    private function reconnect(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $label = $api->type()->label();

        try {
            $this->connections->disconnect($server);
            $connection = $this->connections->for($server);
            $connection->execute('true', timeout: self::SSH_SECONDS)->throw();
            $os = $this->detector->detect($connection);
        } catch (HostKeyChangedException) {
            return StepResult::fail("{$server->name} presented a different SSH host key after the resize. Review it on this page before trying again.");
        } catch (Throwable) {
            return StepResult::wait(self::POLL_SECONDS);
        } finally {
            $this->connections->disconnect($server);
        }

        $this->recordSize($server, self::to($task), $label);

        $server->forceFill([
            'os_name' => $os->name,
            'os_version' => $os->version,
            'meta' => [...(array) $server->meta, ...$os->toMeta()],
            'last_connected_at' => now(),
        ])->save();

        return StepResult::done();
    }

    private function recover(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $reason = (string) ($task->data['reason'] ?? '');
        $label = $api->type()->label();

        try {
            $instance = $api->find(self::id($server));

            if ($instance === null) {
                return StepResult::fail(trim("{$reason} " . self::gone($server, $api)));
            }
        } catch (CloudProviderException) {
            $instance = null;
        }

        $size = $instance?->size;

        if ($size !== null && $size === self::to($task)) {
            $this->recordSize($server, $size, $label);
        }

        if ($instance !== null && in_array($instance->state, self::ON, true)) {
            return StepResult::fail(trim("{$reason} {$server->name} is running on " . ($size ?? self::from($task)) . '.'));
        }

        try {
            $api->powerOn(self::id($server));
        } catch (CloudProviderException $e) {
            self::noteRefusedToken($server, $e);

            return StepResult::fail(trim($size === null
                ? "{$reason} Coresmith couldn't start {$server->name} again, so start it from the {$label} console."
                : "{$reason} Coresmith couldn't start {$server->name} again, so it is off on {$size}. Start it from the {$label} console."));
        }

        return StepResult::fail(trim("{$reason} {$server->name} was started again on " . ($size ?? self::from($task)) . '.'));
    }

    private function recordSize(Server $server, string $size, string $label): void
    {
        if ($server->provider_size === $size) {
            return;
        }

        $price = null;

        try {
            $price = $this->catalog->for($server->cloudProvider)->size($size)?->priceIn((string) $server->region);
        } catch (CloudProviderException) {
            ProvisioningLog::append($server, "couldn't read the new price from {$label}");
        }

        $server->forceFill([
            'provider_size' => $size,
            'provider_monthly_cost' => $price?->monthly,
            'provider_currency' => $price?->currency,
        ])->save();
    }

    private static function noteRefusedToken(Server $server, CloudProviderException $e): void
    {
        if ($e->failure === CloudFailure::TokenRejected) {
            $server->cloudProvider?->forceFill(['token_failed_at' => now()])->save();
        }
    }

    private static function keptFailing(CloudTask $task, CloudApi $api, string $asked): string
    {
        return "{$api->type()->label()} kept failing for 5 minutes when Coresmith asked it to {$asked}." . CreateServerTask::lastError($task->data['last_error'] ?? null);
    }

    private static function stillOff(Server $server, CloudTask $task, CloudApi $api): string
    {
        return "{$server->name} is resized to " . self::to($task) . " but still off. Start it from the {$api->type()->label()} console.";
    }

    private static function gone(Server $server, CloudApi $api): string
    {
        return $api->type()->label() . " says {$server->name} no longer exists.";
    }

    private static function id(Server $server): string
    {
        return (string) $server->provider_server_id;
    }

    private static function from(CloudTask $task): string
    {
        return (string) ($task->data['from'] ?? '');
    }

    private static function to(CloudTask $task): string
    {
        return (string) ($task->data['to'] ?? '');
    }

    private static function powerCycle(CloudTask $task): bool
    {
        return ($task->data['power_cycle'] ?? false) === true;
    }
}
