<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ExplainsFailure;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Services\Deployment\DeployGuard;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Catalog;
use App\Services\Server\RuntimeInstaller;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class UpdateServerRuntime implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = RuntimeInstaller::SECONDS;

    /**
     * @param  list<string>  $applications
     */
    public function __construct(
        public readonly Server $server,
        public readonly string $runtime,
        public readonly string $from,
        public readonly string $to,
        public readonly array $applications,
        public readonly ?User $by = null,
    ) {
        AsdfManager::assertValid($runtime, $to);
        $this->onQueue('provisioning');
    }

    public static function key(Server $server): string
    {
        return 'runtime-update:' . $server->getKey();
    }

    /**
     * @return array{runtime: string, from: string, to: string, label: string, state: string, reason: ?string, applications?: list<string>}|null
     */
    public static function state(Server $server): ?array
    {
        $state = Cache::get(self::key($server));

        return is_array($state) ? $state : null;
    }

    public function remember(string $state, ?string $reason = null): void
    {
        Cache::put(self::key($this->server), [
            'runtime' => $this->runtime,
            'from' => $this->from,
            'to' => $this->to,
            'label' => app(Catalog::class)->label($this->runtime) . ' ' . $this->to,
            'state' => $state,
            'reason' => $reason,
            'applications' => $this->applications,
        ], $state === 'installing' ? now()->addSeconds($this->timeout + 300) : now()->addDay());
    }

    public function handle(SSHConnectionManager $connections, RuntimeInstaller $installer, DeployGuard $guard, Catalog $catalog): void
    {
        $this->remember('installing');

        $connection = $connections->for($this->server);

        try {
            $installer->install($connection, $this->server, $this->runtime, $this->to, fn(string $note) => null);
        } finally {
            $connections->disconnect($this->server);
        }

        $deploying = 0;

        foreach (Application::query()->whereKey($this->applications)->where('server_id', $this->server->getKey())->get() as $application) {
            if (($application->runtimeVersions()[$this->runtime] ?? null) !== $this->from || $guard->refusal($application) !== null) {
                continue;
            }

            $deployment = Deployment::create([
                'application_id' => $application->id,
                'triggered_by' => $this->by?->id,
                'branch' => $application->repository_branch,
            ]);

            DeployApplication::dispatch($deployment, now()->format('Ymd-His'));
            $deploying++;
        }

        Cache::forget(self::key($this->server));

        activity()->performedOn($this->server)->causedBy($this->by)
            ->withProperties(['runtime' => $catalog->label($this->runtime), 'from' => $this->from, 'to' => $this->to, 'apps' => $deploying])
            ->log('runtime_updated');
    }

    public function failed(Throwable $e): void
    {
        $reason = $e instanceof ExplainsFailure ? $e->getMessage() : null;

        $this->remember('failed', $reason);

        activity()->performedOn($this->server)->causedBy($this->by)
            ->withProperties(['runtime' => app(Catalog::class)->label($this->runtime), 'from' => $this->from, 'to' => $this->to, 'reason' => $reason])
            ->log('runtime_update_failed');
    }
}
