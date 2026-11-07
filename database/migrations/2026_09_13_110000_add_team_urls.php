<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use App\Services\Teams\TeamSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('slug', TeamSlug::MAX_LENGTH)->nullable()->after('name');
        });

        $taken = [];

        foreach (DB::table('teams')->orderBy('created_at')->orderBy('id')->get(['id', 'name']) as $team) {
            $base = trim(Str::limit(Str::slug((string) $team->name), TeamSlug::MAX_LENGTH - 4, ''), '-');
            $base = TeamSlug::isValid($base) && !in_array($base, TeamSlug::RESERVED, true) ? $base : 'team';
            $slug = $base;

            for ($suffix = 2; isset($taken[$slug]); $suffix++) {
                $slug = $base . '-' . $suffix;
            }

            $taken[$slug] = true;

            DB::table('teams')->where('id', $team->id)->update(['slug' => $slug]);
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->unique('slug');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->ulid('current_team_id')->nullable()->index();
        });

        Schema::create('team_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('team_id')->unique();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->string('hostname', 253)->unique();
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('failure')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_domains');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['current_team_id']);
            $table->dropColumn('current_team_id');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
