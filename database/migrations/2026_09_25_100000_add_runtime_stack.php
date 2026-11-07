<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('bun_version')->nullable()->after('ruby_version');
            $table->string('deno_version')->nullable()->after('bun_version');
            $table->json('runtime_pins')->nullable()->after('deno_version');
            $table->json('stack')->nullable()->after('runtime_pins');
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->json('stack')->nullable()->after('failure_reason');
            $table->timestamp('stale_after')->nullable()->after('started_at');
        });

        DB::table('applications')->orderBy('id')->each(function (object $application): void {
            $pins = array_filter([
                'php' => $application->php_version,
                'nodejs' => $application->node_version,
                'ruby' => $application->ruby_version,
            ], fn(?string $version): bool => $version !== null && $version !== '');

            if ($pins !== []) {
                DB::table('applications')->where('id', $application->id)->update(['runtime_pins' => json_encode($pins)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['bun_version', 'deno_version', 'runtime_pins', 'stack']);
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn(['stack', 'stale_after']);
        });
    }
};
