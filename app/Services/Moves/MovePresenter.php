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
     * @return array{code: array{id: string, text: string, until: string, preview: array<string, mixed>|null}|null, billing: ?string}|null
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

        $billing = $cloud ? route('settings.billing') : null;

        if ($code === null) {
            return ['code' => null, 'billing' => $billing];
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
        ], 'billing' => $billing];
    }

    /**
     * @return array{moving: array{since: string, to: string, where: string, file: ?string, can_cancel: bool}|null}|null
     */
    public function away(Team $team, User $user): ?array
    {
        if (!Edition::current()->isSelfHosted() || !$user->can('manageBilling', $team)) {
            return null;
        }

        if ($team->moving_at === null) {
            return ['moving' => null];
        }

        return ['moving' => [
            'since' => $team->moving_at->setTimezone((string) config('app.timezone', 'UTC'))->format('M j, H:i'),
            'to' => $this->destination($team),
            'where' => $this->where($team),
            'file' => $team->move_file === null ? null : route('settings.moving.file'),
            'can_cancel' => true,
        ]];
    }

    /**
     * @return array{banner: array{tone: string, title: string, body: string, action: ?string}, href: string}|null
     */
    public function banner(Team $team, User $user): ?array
    {
        if (!Edition::current()->isSelfHosted() || $team->moving_at === null) {
            return null;
        }

        $to = $this->destination($team);
        $manages = $user->can('manageBilling', $team);
        $upload = "Upload the move file {$this->where($team)} to finish.";

        return [
            'banner' => [
                'tone' => 'warning',
                'title' => "This team is moving to {$to}.",
                'body' => 'Coresmith has stopped managing its servers, and they keep running. ' . ($manages ? $upload : 'Ask an owner if it should stay here.'),
                'action' => $manages ? 'See the move' : null,
            ],
            'href' => route('settings.general'),
        ];
    }

    private function where(Team $team): string
    {
        return ($team->moving_edition === Edition::Cloud->value ? 'in ' : 'on ') . $this->destination($team);
    }

    private function destination(Team $team): string
    {
        if ($team->moving_edition === Edition::Cloud->value) {
            return MoveSide::label(Edition::Cloud);
        }

        $host = parse_url((string) $team->moving_to, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'the Coresmith the team is moving to';
    }
}
