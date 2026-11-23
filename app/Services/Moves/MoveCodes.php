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
use App\Services\Network\LocalAddresses;
use App\Services\Network\PublicAddress;
use App\Services\Teams\TeamUrls;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class MoveCodes
{
    public const PREFIX = 'csmove1_';

    public const HOURS = 24;

    private const MAX_ADDRESSES = 8;

    public function __construct(private readonly TeamUrls $urls, private readonly LocalAddresses $addresses) {}

    public function create(User $user, ?Team $team = null): MoveCode
    {
        $pair = sodium_crypto_box_keypair();

        $code = new MoveCode;
        $code->forceFill([
            'user_id' => $user->getKey(),
            'team_id' => $team?->getKey(),
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
            'edition' => Edition::current()->value,
        ];

        if (Edition::current()->isCloud()) {
            $claims['reach'] = self::publicAddresses($this->addresses->all());
        }

        return self::PREFIX . rtrim(strtr(base64_encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    public function read(string $text): MoveTarget
    {
        $claims = $this->claims(trim($text));
        $key = base64_decode((string) ($claims['key'] ?? ''), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES || !is_string($claims['id'] ?? null) || !is_int($claims['until'] ?? null) || !is_string($claims['panel'] ?? null)) {
            $this->refuse("That isn't a move code. Copy it again from Settings, General on " . MoveSide::destination() . '.');
        }

        $until = CarbonImmutable::createFromTimestamp($claims['until']);

        if (!$until->isFuture()) {
            $this->refuse('This move code has expired. Create a new one in Settings, General on ' . MoveSide::destination() . '.');
        }

        return new MoveTarget($claims['id'], $key, $until, $claims['panel'], Edition::tryFrom((string) ($claims['edition'] ?? '')) ?? Edition::SelfHosted, self::publicAddresses(is_array($claims['reach'] ?? null) ? $claims['reach'] : []));
    }

    /**
     * @param  array<mixed>  $addresses
     * @return list<string>
     */
    private static function publicAddresses(array $addresses): array
    {
        return array_slice(array_values(array_unique(array_filter($addresses, fn(mixed $address): bool => is_string($address) && PublicAddress::is($address)))), 0, self::MAX_ADDRESSES);
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(string $text): array
    {
        if (!str_starts_with($text, self::PREFIX)) {
            $this->refuse("That isn't a move code. Copy it again from Settings, General on " . MoveSide::destination() . '.');
        }

        $json = base64_decode(strtr(substr($text, strlen(self::PREFIX)), '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);

        if (!is_array($claims) || ($claims['v'] ?? null) !== 1) {
            $this->refuse("That isn't a move code. Copy it again from Settings, General on " . MoveSide::destination() . '.');
        }

        return $claims;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}
