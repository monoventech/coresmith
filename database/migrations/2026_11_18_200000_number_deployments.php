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
            $table->unsignedInteger('deploy_count')->default(0);
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->unsignedInteger('number')->nullable();
        });

        DB::table('applications')->select('id')->orderBy('id')->chunk(200, function ($applications): void {
            foreach ($applications as $application) {
                $number = 0;

                foreach (DB::table('deployments')->where('application_id', $application->id)->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
                    DB::table('deployments')->where('id', $id)->update(['number' => ++$number]);
                }

                DB::table('applications')->where('id', $application->id)->update(['deploy_count' => $number]);
            }
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->unique(['number', 'application_id']);
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropUnique(['number', 'application_id']);
            $table->dropColumn('number');
        });

        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('deploy_count');
        });
    }
};
