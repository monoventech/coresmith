<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Teams\Handles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

trait HasHandle
{
    abstract protected function handleFallback(): string;

    protected function handleAlsoBy(): ?string
    {
        return null;
    }

    public static function bootHasHandle(): void
    {
        static::replicating(function (Model $model): void {
            $model->setAttribute('handle', null);
            $model->setAttribute('former_handles', null);
        });

        static::creating(function (Model $model): void {
            $model->setAttribute('handle', $model->getAttribute('handle') ?? app(Handles::class)->for($model, $model->handleFallback()));
        });

        static::updating(function (Model $model): void {
            if (!$model->isDirty('name') || $model->isDirty('handle') || (method_exists($model, 'trashed') && $model->trashed())) {
                return;
            }

            $old = $model->getOriginal('handle');
            $new = app(Handles::class)->for($model, $model->handleFallback());

            if ($new === $old) {
                return;
            }

            $former = array_values(array_diff((array) $model->getAttribute('former_handles'), [$new]));
            $model->setAttribute('former_handles', is_string($old) && $old !== '' ? array_values(array_unique([...$former, $old])) : $former);
            $model->setAttribute('handle', $new);
        });

        static::deleted(function (Model $model): void {
            if (!method_exists($model, 'isForceDeleting') || $model->isForceDeleting() || $model->getAttribute('handle') === null) {
                return;
            }

            $model->newQueryWithoutScopes()->whereKey($model->getKey())->update(['handle' => null]);
            $model->setAttribute('handle', null);
            $model->syncOriginalAttribute('handle');
        });
    }

    public function initializeHasHandle(): void
    {
        $this->mergeCasts(['former_handles' => 'array']);
    }

    public function getRouteKey(): mixed
    {
        return $this->getAttribute('handle') ?? $this->getKey();
    }

    /**
     * @param  Model|Builder<Model>|Relation<Model, Model, mixed>  $query
     * @return Builder<Model>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if ($field !== null && !in_array($field, [$this->getRouteKeyName(), $this->qualifyColumn($this->getRouteKeyName())], true)) {
            return parent::resolveRouteBindingQuery($query, $value, $field);
        }

        $builder = match (true) {
            $query instanceof Model => $query->newQuery(),
            $query instanceof Relation => $query->getQuery(),
            default => $query,
        };

        return app(Handles::class)->scope($builder, (string) $value, $this->handleAlsoBy());
    }
}
