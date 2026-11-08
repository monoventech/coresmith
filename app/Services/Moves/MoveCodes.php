<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Models\MoveCode;
use App\Models\User;
use App\Services\Teams\TeamUrls;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class MoveCodes
{
    public const PREFIX = 'csmove1_';

    public const HOURS = 24;

    public function __construct(private readonly TeamUrls $urls) {}

    public function create(User $user): MoveCode
    {
        $pair = sodium_crypto_box_keypair();

        $code = new MoveCode;
        $code->forceFill([
            'user_id' => $user->getKey(),
            'public_key' => base64_encode(sodium_crypto_box_publickey($pair)),
            'secret_key' => base64_encode($pair),
            'expires_at' => now()->addHours(self::HOURS),
        ])->save();

        sodium_memzero($pair);

        return $code;
    }

    public function text(MoveCode $code): string
    {
        $claims = [
            'v' => 1,
            'id' => $code->id,
            'key' => $code->public_key,
            'until' => $code->expires_at->getTimestamp(),
            'panel' => rtrim($this->urls->canonical(), '/'),
        ];

        return self::PREFIX . rtrim(strtr(base64_encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    public function read(string $text): MoveTarget
    {
        $claims = $this->claims(trim($text));
        $key = base64_decode((string) ($claims['key'] ?? ''), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES || !is_string($claims['id'] ?? null) || !is_int($claims['until'] ?? null) || !is_string($claims['panel'] ?? null)) {
            $this->refuse("That isn't a move code. Copy it again from Settings, General on your panel.");
        }

        $until = CarbonImmutable::createFromTimestamp($claims['until']);

        if (!$until->isFuture()) {
            $this->refuse('This move code has expired. Create a new one in Settings, General on your panel.');
        }

        return new MoveTarget($claims['id'], $key, $until, $claims['panel']);
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(string $text): array
    {
        if (!str_starts_with($text, self::PREFIX)) {
            $this->refuse("That isn't a move code. Copy it again from Settings, General on your panel.");
        }

        $json = base64_decode(strtr(substr($text, strlen(self::PREFIX)), '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);

        if (!is_array($claims) || ($claims['v'] ?? null) !== 1) {
            $this->refuse("That isn't a move code. Copy it again from Settings, General on your panel.");
        }

        return $claims;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}
