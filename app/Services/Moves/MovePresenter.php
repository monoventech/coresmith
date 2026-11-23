<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\Edition;
use App\Models\MoveCode;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class MovePresenter
{
    public function __construct(
        private readonly MoveCodes $codes,
        private readonly MoveFile $files,
        private readonly TeamImporter $importer,
    ) {}

    /**
     * @return array{code: array{id: string, text: string, until: string, preview: array<string, mixed>|null}|null}|null
     */
    public function forUser(User $user, ?Team $team = null): ?array
    {
        $cloud = Edition::current()->isCloud();

        if ($cloud ? !$team instanceof Team || !$user->can('manageBilling', $team) || $team->servers()->exists() : !$user->managesInstance()) {
            return null;
        }

        $code = MoveCode::query()
            ->where('user_id', $user->getKey())
            ->when($cloud, fn($query) => $query->where('team_id', $team?->getKey()))
            ->whereNull('imported_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($code === null) {
            return ['code' => null];
        }

        $preview = null;

        if ($code->file !== null) {
            try {
                $preview = $this->importer->preview($this->files->open((string) $code->file, $code), $cloud ? $team : null, $user);
            } catch (ValidationException) {
                $preview = null;
            }
        }

        return ['code' => [
            'id' => $code->id,
            'text' => $this->codes->text($code),
            'until' => $code->expires_at->setTimezone((string) config('app.timezone', 'UTC'))->format('M j, H:i'),
            'preview' => $preview,
        ]];
    }
}
