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
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->ulid('project_id')->nullable()->index();
            $table->json('tags')->nullable();
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->ulid('project_id')->nullable()->index();
        });

        $environments = DB::table('environments')->orderBy('position')->orderBy('id')->get(['id', 'project_id', 'name']);

        $this->file($environments);
        $this->convertGrants($environments);

        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'environment_id']);
        });

        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropColumn('environment_id');
        });

        foreach (['applications', 'databases'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['environment_id']);
            });

            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('environment_id');
            });
        }

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('environment');
        });

        Schema::dropIfExists('environments');
    }

    public function down(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        foreach (['applications', 'databases'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['project_id']);
            });

            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropColumn($name === 'applications' ? ['project_id', 'tags'] : ['project_id']);
            });

            Schema::table($name, function (Blueprint $table) {
                $table->ulid('environment_id')->nullable()->index();
            });
        }

        Schema::table('access_grants', function (Blueprint $table) {
            $table->ulid('environment_id')->nullable();
            $table->unique(['user_id', 'environment_id']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('environment')->nullable()->after('name');
        });
    }

    /**
     * @param  Collection<int, object>  $environments
     */
    private function file(Collection $environments): void
    {
        $counts = $environments->countBy('project_id');

        foreach ($environments as $environment) {
            $untouched = $counts->get($environment->project_id) === 1 && $environment->name === 'Production';

            DB::table('applications')->where('environment_id', $environment->id)->update([
                'project_id' => $environment->project_id,
                ...($untouched ? [] : ['tags' => json_encode([$environment->name])]),
            ]);

            DB::table('databases')->where('environment_id', $environment->id)->update(['project_id' => $environment->project_id]);
        }
    }

    /**
     * @param  Collection<int, object>  $environments
     */
    private function convertGrants(Collection $environments): void
    {
        $projectOf = $environments->pluck('project_id', 'id');
        $whole = $environments->groupBy('project_id')->map(fn(Collection $group): array => $group->pluck('id')->sort()->values()->all());

        $grants = DB::table('access_grants')->whereNotNull('environment_id')->get()
            ->filter(fn(object $grant): bool => $projectOf->has($grant->environment_id))
            ->groupBy(fn(object $grant): string => $grant->team_id . '|' . $grant->user_id . '|' . $projectOf->get($grant->environment_id));

        foreach ($grants as $group) {
            $first = $group->first();
            $project = $projectOf->get($first->environment_id);
            $granted = $group->pluck('environment_id')->unique()->sort()->values()->all();

            if ($granted === $whole->get($project)) {
                $this->allow($first->team_id, $first->user_id, 'project_id', $project);

                continue;
            }

            foreach (DB::table('applications')->whereIn('environment_id', $granted)->pluck('id') as $application) {
                $this->allow($first->team_id, $first->user_id, 'application_id', $application);
            }

            foreach (DB::table('databases')->whereIn('environment_id', $granted)->pluck('id') as $database) {
                $this->allow($first->team_id, $first->user_id, 'database_id', $database);
            }
        }

        DB::table('access_grants')->whereNotNull('environment_id')->delete();
    }

    private function allow(string $team, string $user, string $column, string $id): void
    {
        $existing = DB::table('access_grants')->where('user_id', $user)->where($column, $id)->first();

        if ($existing !== null) {
            DB::table('access_grants')->where('id', $existing->id)->update(['withheld' => false, 'updated_at' => now()]);

            return;
        }

        DB::table('access_grants')->insert(['team_id' => $team, 'user_id' => $user, $column => $id, 'withheld' => false, 'created_at' => now(), 'updated_at' => now()]);
    }
};
