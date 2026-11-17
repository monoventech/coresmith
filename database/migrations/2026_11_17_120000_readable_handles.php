<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use App\Services\Teams\Handles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['servers' => 'server', 'projects' => 'project', 'applications' => 'app'];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('handle', 64)->nullable()->after('name');
                $blueprint->json('former_handles')->nullable()->after('handle');
            });
        }

        foreach (self::TABLES as $table => $fallback) {
            $taken = [];

            $rows = DB::table($table)->when(Schema::hasColumn($table, 'deleted_at'), fn($query) => $query->whereNull('deleted_at'));

            foreach ($rows->orderBy('created_at')->orderBy('id')->get(['id', 'team_id', 'name']) as $row) {
                $team = (string) $row->team_id;
                $base = Handles::base((string) $row->name, $fallback);
                $handle = $base;
                $n = 2;

                while (isset($taken[$team][$handle]) || Handles::reserved($handle)) {
                    $handle = Handles::suffixed($base, $n++);
                }

                $taken[$team][$handle] = true;
                DB::table($table)->where('id', $row->id)->update(['handle' => $handle]);
            }

            Schema::table($table, fn(Blueprint $blueprint) => $blueprint->unique(['handle', 'team_id']));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['handle', 'team_id']);
                $blueprint->dropColumn(['handle', 'former_handles']);
            });
        }
    }
};
