<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Models\MoveCode;
use Illuminate\Validation\ValidationException;
use SodiumException;

final class MoveFile
{
    public const FORMAT = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function seal(array $payload, MoveTarget $target): string
    {
        $sealed = sodium_crypto_box_seal((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $target->publicKey);

        return json_encode([
            'format' => self::FORMAT,
            'coresmith' => (string) config('coresmith.version'),
            'code' => $target->id,
            'sealed' => base64_encode($sealed),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function open(string $file, MoveCode $code): array
    {
        $header = json_decode($file, true);

        if (!is_array($header) || !is_int($header['format'] ?? null) || !is_string($header['code'] ?? null) || !is_string($header['sealed'] ?? null)) {
            $this->refuse("This isn't a Coresmith move file. Download it again from Coresmith Cloud.");
        }

        if ($header['format'] > self::FORMAT) {
            $version = is_string($header['coresmith'] ?? null) ? $header['coresmith'] : 'a newer version';
            $this->refuse("This move file comes from Coresmith {$version}, which is newer than this one. Update Coresmith, then upload the file again.");
        }

        if (!hash_equals((string) $code->id, $header['code'])) {
            $this->refuse('This move file was made for a different move code. Paste the code from here into Coresmith Cloud and download the file again.');
        }

        if ($code->imported_at !== null || $code->secret_key === null) {
            $this->refuse('This move code has already been used. Create a new one, then download the file again in Coresmith Cloud.');
        }

        if (!$code->expires_at->isFuture()) {
            $this->refuse('This move code has expired. Create a new one, then download the file again in Coresmith Cloud.');
        }

        $sealed = base64_decode($header['sealed'], true);
        $pair = base64_decode((string) $code->secret_key, true);

        try {
            $plain = $sealed === false || $pair === false ? false : sodium_crypto_box_seal_open($sealed, $pair);
        } catch (SodiumException) {
            $plain = false;
        }

        $payload = $plain === false ? null : json_decode($plain, true);

        if (!is_array($payload)) {
            $this->refuse("This move file can't be opened. Download it again from Coresmith Cloud.");
        }

        return $payload;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
