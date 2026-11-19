<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\TeamInvitation;

final class InvitationLinks
{
    public static function route(string $name, string $token): string
    {
        $invitation = TeamInvitation::query()->where('token_hash', TeamInvitation::hash($token))->first();

        return route($name, ['slug' => (string) ($invitation?->team?->slug ?? 'gone'), 'token' => $token]);
    }
}
