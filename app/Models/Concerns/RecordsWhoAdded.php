<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait RecordsWhoAdded
{
    protected static function bootRecordsWhoAdded(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('created_by') === null && auth()->id() !== null) {
                $model->setAttribute('created_by', auth()->id());
            }
        });
    }

    public function addedBy(User $user): bool
    {
        return $this->getAttribute('created_by') !== null && $this->getAttribute('created_by') === $user->getKey();
    }
}
