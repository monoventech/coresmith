<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CloudFailure;
use App\Exceptions\CloudProviderException;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CloudTaskHandler;
use App\Services\Cloud\Tasks\CloudTaskHandlers;
use App\Services\Cloud\Tasks\StepResult;
use App\Services\Deployment\LogSanitizer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

final class RunCloudTask implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const BUDGET_SECONDS = 90;

    public const MAX_STEPS = 25;

    public const RETRY_SECONDS = 30;

    public const NO_ACCOUNT = 'The provider account this server was created with is no longer connected. Connect it again in Settings → Team, then try again.';

    public int $tries = 1;

    public int $timeout = 150;

    public int $uniqueFor = 600;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('server-tasks');
    }

    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('cloud-task:' . $this->server->getKey()))->expireAfter(180)->dontRelease()];
    }

    public function handle(CloudApiFactory $apis, CloudTaskHandlers $handlers, LogSanitizer $sanitizer): void
    {
        $waited = 0;
        $began = microtime(true);

        for ($steps = 0; $steps < self::MAX_STEPS; $steps++) {
            $server = Server::query()->find($this->server->getKey());
            $task = $server === null ? null : CloudTask::of($server);

            if ($server === null || $task === null || $task->state !== CloudTask::RUNNING) {
                return;
            }

            $handler = $handlers->for($task->type);
            $account = $server->cloudProvider;

            if ($account === null || $account->trashed()) {
                $this->conclude($server, $task, $handler, null, StepResult::fail(self::NO_ACCOUNT));

                return;
            }

            [$api, $result] = $this->attempt($apis, $account, $server, $task, $handler, $sanitizer);

            if ($result->kind === 'next') {
                CloudTask::advance($server, (string) $result->step, $result->data);

                continue;
            }

            if ($result->kind === 'wait') {
                CloudTask::touch($server, $result->data);

                if ($waited + $result->seconds > self::BUDGET_SECONDS || microtime(true) - $began > self::BUDGET_SECONDS) {
                    self::dispatch($server)->delay(now()->addSeconds($result->seconds));

                    return;
                }

                $waited += $result->seconds;
                Sleep::for($result->seconds)->seconds();

                continue;
            }

            $this->conclude($server, $task, $handler, $api, $result);

            return;
        }

        self::dispatch($this->server)->delay(now()->addSeconds(5));
    }

    /**
     * @return array{0: ?CloudApi, 1: StepResult}
     */
    private function attempt(CloudApiFactory $apis, CloudProvider $account, Server $server, CloudTask $task, CloudTaskHandler $handler, LogSanitizer $sanitizer): array
    {
        $api = null;

        try {
            $api = $apis->for($account);
            $expired = $task->stepSeconds() > (int) config("coresmith.cloud.step_seconds.{$task->step}", 600);

            return [$api, $expired ? $handler->expired($server, $task, $api) : $handler->step($server, $task, $api)];
        } catch (CloudProviderException $e) {
            if ($e->failure === CloudFailure::TokenRejected) {
                $account->forceFill(['token_failed_at' => now()])->save();
            }

            return [$api, $e->retryable() ? StepResult::wait(self::RETRY_SECONDS, ['last_error' => $e->getMessage()]) : StepResult::fail($e->getMessage())];
        } catch (Throwable $e) {
            report($e);

            return [$api, StepResult::fail('Coresmith stopped with an unexpected error: ' . rtrim(Str::limit($sanitizer->sanitize($e->getMessage()), 200), '. ') . ". Try again, and if it keeps happening check the panel's logs.")];
        }
    }

    private function conclude(Server $server, CloudTask $task, CloudTaskHandler $handler, ?CloudApi $api, StepResult $result): void
    {
        if ($result->data !== []) {
            CloudTask::touch($server, $result->data);
        }

        if ($result->kind === 'done') {
            CloudTask::finish($server);
            $fresh = $server->fresh() ?? $server;
            $handler->finished($fresh, CloudTask::of($fresh) ?? $task, $api);

            return;
        }

        CloudTask::fail($server, (string) $result->reason);
        $fresh = $server->fresh() ?? $server;
        $handler->failed($fresh, CloudTask::of($fresh) ?? $task, $api, (string) $result->reason);
    }
}
