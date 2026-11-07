<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'source_providers_github_installation_unique';

    private const TEAM_INDEX = 'source_providers_team_id_index';

    private const STATE = ['name', 'suspended_at', 'repository_selection', 'access_token', 'refresh_token', 'token_expires_at'];

    public function up(): void
    {
        DB::table('source_providers')
            ->where('type', 'github_app')
            ->whereNotNull('installation_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'team_id', 'github_app_id', 'installation_id', 'deleted_at'])
            ->groupBy(fn(object $connection): string => implode('|', [$connection->team_id, $connection->github_app_id ?? '', $connection->installation_id]))
            ->filter(fn(Collection $connections): bool => $connections->count() > 1)
            ->each(fn(Collection $connections) => $this->merge($connections));

        if (!Schema::hasIndex('source_providers', self::TEAM_INDEX)) {
            Schema::table('source_providers', function (Blueprint $table) {
                $table->index('team_id', self::TEAM_INDEX);
            });
        }

        Schema::table('source_providers', function (Blueprint $table) {
            $table->unique(['team_id', 'type', 'github_app_id', 'installation_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('source_providers', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }

    /**
     * @param  Collection<int, object>  $connections
     */
    private function merge(Collection $connections): void
    {
        $kept = $connections->first();
        $merged = $connections->slice(1)->pluck('id')->all();
        $connected = $connections->slice(1)->filter(fn(object $connection): bool => $connection->deleted_at === null)->last();

        DB::table('applications')->whereIn('source_provider_id', $merged)->update(['source_provider_id' => $kept->id]);

        if ($kept->deleted_at !== null && $connected !== null) {
            $state = (array) DB::table('source_providers')->where('id', $connected->id)->first(self::STATE);

            DB::table('source_providers')->where('id', $kept->id)->update([...$state, 'deleted_at' => null]);
        }

        DB::table('source_providers')->whereIn('id', $merged)->delete();
    }
};
