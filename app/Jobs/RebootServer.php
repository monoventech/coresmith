<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\CloudProviderException;
use App\Models\Server;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RebootServer implements ShouldQueue
{
    use Queueable;

    public const COMMAND = 'systemd-run --on-active=5 --timer-property=AccuracySec=1s systemctl reboot';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('default');
    }

    public function handle(SSHConnectionManager $connections, CloudApiFactory $apis): void
    {
        $account = $this->server->cloudProvider;

        if ($account !== null && filled($this->server->provider_server_id)) {
            $this->throughProvider($apis, $account->type->label());

            return;
        }

        $connection = $connections->for($this->server);

        try {
            $connection->execute(self::COMMAND, sudo: true)->throw();
        } finally {
            $connections->disconnect($this->server);
        }

        activity()->performedOn($this->server)->log('server_rebooted');
    }

    private function throughProvider(CloudApiFactory $apis, string $label): void
    {
        try {
            $apis->for($this->server->cloudProvider)->reboot((string) $this->server->provider_server_id);
        } catch (CloudProviderException $e) {
            activity()->performedOn($this->server)->withProperties(['reason' => $e->getMessage()])->log('server_reboot_failed');

            throw $e;
        }

        activity()->performedOn($this->server)->withProperties(['via' => $label])->log('server_rebooted');
    }
}
