<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Tasks;

use App\Enums\CloudFailure;
use App\Exceptions\CloudProviderException;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Server\ProvisioningLog;

final class DestroyServerTask implements CloudTaskHandler
{
    public const STEPS = ['delete'];

    public const POLL_SECONDS = 5;

    public function step(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $label = $api->type()->label();
        $id = self::instance($server, $task) ?? $api->findTagged(CreateServerTask::tag($server))?->id;

        if ($id === null) {
            return self::gone($server, $label);
        }

        if (($task->data['requested'] ?? false) === true) {
            return $api->find($id) === null ? self::gone($server, $label) : StepResult::wait(self::POLL_SECONDS);
        }

        try {
            $api->destroy($id);
        } catch (CloudProviderException $e) {
            if ($e->failure === CloudFailure::Refused) {
                return StepResult::fail($e->getMessage() . " Try again once that's fixed, or delete it in the {$label} console and then detach it here.");
            }

            if ($e->failure !== CloudFailure::NotFound) {
                throw $e;
            }

            return self::gone($server, $label);
        }

        return StepResult::wait(self::POLL_SECONDS, ['requested' => true, 'instance' => $id]);
    }

    public function expired(Server $server, CloudTask $task, CloudApi $api): StepResult
    {
        $label = $api->type()->label();

        return StepResult::fail("Coresmith couldn't destroy {$server->name} at {$label} within 10 minutes." . CreateServerTask::lastError($task->data['last_error'] ?? null) . " It may still be running and billed: try again, or delete it in the {$label} console and then detach it here.");
    }

    public function finished(Server $server, CloudTask $task, CloudApi $api): void
    {
        $label = $api->type()->label();
        $keyId = $task->data['key_id'] ?? null;

        if (is_string($keyId) && $keyId !== '') {
            try {
                $api->deleteKey($keyId);
            } catch (CloudProviderException) {
                ProvisioningLog::append($server, "couldn't remove the uploaded key from {$label}, delete " . CreateServerTask::keyName($server) . ' there');
            }
        }

        activity()
            ->performedOn($server)
            ->causedBy($task->by === null ? null : User::query()->find($task->by))
            ->withProperties(['provider' => $label, 'size' => $server->provider_size])
            ->log('server_destroyed');

        $server->delete();
    }

    public function failed(Server $server, CloudTask $task, ?CloudApi $api, string $reason): void
    {
        activity()
            ->performedOn($server)
            ->withProperties(['provider' => ($api?->type() ?? $server->cloudProvider?->type)?->label() ?? 'the provider', 'reason' => $reason])
            ->log('server_destroy_failed');
    }

    private static function instance(Server $server, CloudTask $task): ?string
    {
        if (filled($server->provider_server_id)) {
            return (string) $server->provider_server_id;
        }

        $instance = $task->data['instance'] ?? null;

        return is_string($instance) && $instance !== '' ? $instance : null;
    }

    private static function gone(Server $server, string $label): StepResult
    {
        ProvisioningLog::append($server, "deleted at {$label}");

        return StepResult::done();
    }
}
