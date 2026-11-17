<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Http\Middleware\ResolveTeamUrl;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class Handles
{
    public const MAX = 60;

    private const RESERVED = ['cloud', 'this-machine', 'create', 'new', 'edit'];

    public static function base(string $name, string $fallback): string
    {
        $base = trim(Str::substr(Str::slug($name), 0, self::MAX), '-');

        return $base === '' ? $fallback : $base;
    }

    public static function suffixed(string $base, int $n): string
    {
        $suffix = '-' . $n;

        return rtrim(Str::substr($base, 0, self::MAX - strlen($suffix)), '-') . $suffix;
    }

    public static function reserved(string $handle): bool
    {
        return in_array($handle, self::RESERVED, true);
    }

    public function for(Model $model, string $fallback): string
    {
        $base = self::base((string) $model->getAttribute('name'), $fallback);
        $handle = $base;
        $n = 2;

        while (self::reserved($handle) || $this->taken($model, $handle)) {
            $handle = self::suffixed($base, $n++);
        }

        return $handle;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scope(Builder $query, string $value, ?string $alsoBy = null): Builder
    {
        $teams = $this->teams();
        $model = $query->getModel();
        $column = $model->qualifyColumn('team_id');
        $handle = $model->qualifyColumn('handle');

        return $query->where(function (Builder $match) use ($value, $teams, $column, $handle, $alsoBy, $model): void {
            $match->where(fn(Builder $byHandle) => $byHandle
                ->whereIn($column, $teams)
                ->where(fn(Builder $names) => $names->where($handle, $value)->orWhereJsonContains($model->qualifyColumn('former_handles'), $value)))
                ->orWhere($model->getQualifiedKeyName(), Str::lower($value));

            if ($alsoBy !== null) {
                $match->orWhere($model->qualifyColumn($alsoBy), $value);
            }
        })
            ->when($teams !== [], fn(Builder $ordered) => $ordered->orderByRaw('case when ' . $column . ' = ? then 0 else 1 end', [$teams[0]]))
            ->orderByRaw('case when ' . $handle . ' = ? then 0 else 1 end', [$value]);
    }

    /**
     * @return list<string>
     */
    private function teams(): array
    {
        $request = request();
        $named = $request->attributes->get(ResolveTeamUrl::ATTRIBUTE);

        if (is_array($named) && isset($named[0])) {
            return [(string) $named[0]];
        }

        $user = $request->user();

        if (!$user instanceof User) {
            return [];
        }

        $token = $user->currentAccessToken();

        if ($token instanceof ApiToken && $token->team_id !== null) {
            return [(string) $token->team_id];
        }

        $teams = $user->teams()->pluck('teams.id')->map(fn(mixed $id): string => (string) $id)->all();
        $current = (string) $user->current_team_id;

        return array_values(array_unique(in_array($current, $teams, true) ? [$current, ...$teams] : $teams));
    }

    private function taken(Model $model, string $handle): bool
    {
        $query = $model->newQueryWithoutScopes()
            ->where('team_id', $model->getAttribute('team_id'))
            ->where('handle', $handle);

        if ($model->exists) {
            $query->whereKeyNot($model->getKey());
        }

        return $query->exists();
    }
}
