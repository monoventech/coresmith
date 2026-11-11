<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class OffsiteDownloadFailed extends RuntimeException
{
    public static function noAddress(): self
    {
        return new self('Could not build the download address. Check the bucket, region and endpoint in the S3 settings.');
    }

    public static function rejected(string $output): self
    {
        $line = collect(preg_split('/\r?\n/', $output) ?: [])->map(fn(string $line): string => trim($line))->first(fn(string $line): bool => $line !== '') ?? 'no reason given';

        return new self("S3 refused the download: {$line}. Check the bucket name, region and keys.");
    }

    public static function short(int $received, int $expected): self
    {
        return new self("The download from S3 stopped early: {$received} of {$expected} bytes arrived. Try again.");
    }
}
