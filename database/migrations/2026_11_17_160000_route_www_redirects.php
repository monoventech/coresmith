<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Services\Address\DomainTwins;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $twins = app(DomainTwins::class);

        Application::query()
            ->whereNotNull('current_release')
            ->where(fn(Builder $query) => $query->whereNotNull('domain')->orWhereNotNull('additional_domains'))
            ->each(function (Application $application) use ($twins): void {
                if ($twins->for($application) !== []) {
                    SyncApplicationRoute::dispatch($application);
                }
            });
    }

    public function down(): void {}
};
