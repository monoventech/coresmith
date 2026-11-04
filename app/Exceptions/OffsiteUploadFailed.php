<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Number;
use RuntimeException;

final class OffsiteUploadFailed extends RuntimeException
{
    public static function tooLarge(int $bytes): self
    {
        return new self('The dump is ' . Number::fileSize($bytes, maxPrecision: 1) . ', over the 5 GB a single upload can carry, so it stayed on the server.');
    }

    public static function dumpMissing(string $file, string $server): self
    {
        return new self("The dump {$file} is not on {$server}, so nothing was sent. Back up again.");
    }

    public static function noAddress(): self
    {
        return new self('Could not build the upload address. Check the bucket, region and endpoint in the S3 settings.');
    }

    public static function rejected(string $output): self
    {
        $line = collect(preg_split('/\r?\n/', $output) ?: [])->map(fn(string $line): string => trim($line))->first(fn(string $line): bool => $line !== '') ?? 'no reason given';

        return new self("The bucket refused the upload: {$line}. Check the bucket name, region and keys.");
    }
}
