<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Monitors\AppAnswers;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CheckAppsAnswering implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    public function handle(AppAnswers $answers): void
    {
        $answers->check($this->server);
    }
}
