<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BackupDestination;
use App\Services\Database\OffsiteBackups;
use App\Services\Panel\BackupCopy;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Phar;
use PharData;
use Throwable;

final class CopyCoresmithBackup implements ShouldQueue
{
    use Queueable;

    public const DATABASE_ENTRIES = ['database.sqlite', 'database.sql'];

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $archive) {}

    public function handle(OffsiteBackups $offsite): void
    {
        $destination = BackupDestination::forCoresmith();

        if (!$destination instanceof BackupDestination || !is_file($this->archive)) {
            return;
        }

        $key = BackupCopy::key($destination, $this->archive);
        $copy = null;

        try {
            $copy = $this->databaseOnly();
            $response = Http::timeout(OffsiteBackups::UPLOAD_SECONDS)
                ->withBody(Utils::streamFor(fopen($copy, 'rb')), 'application/gzip')
                ->put($offsite->presignedPut($destination, $key));

            BackupCopy::record($response->successful(), $key, $response->successful() ? null : "The bucket refused the copy: {$response->status()}. Check the bucket name, region and keys.");
        } catch (Throwable) {
            BackupCopy::record(false, $key, "Coresmith couldn't reach the bucket. Check the endpoint and region, then back up again.");
        } finally {
            if ($copy !== null) {
                File::delete($copy);
            }
        }
    }

    private function databaseOnly(): string
    {
        $work = sys_get_temp_dir() . '/coresmith-copy-' . Str::lower(Str::random(12));
        File::ensureDirectoryExists($work, 0700);

        try {
            $phar = new PharData($this->archive);

            foreach (self::DATABASE_ENTRIES as $entry) {
                if (isset($phar[$entry])) {
                    $phar->extractTo($work, $entry);
                }
            }

            $tar = $work . '.tar';
            $copy = new PharData($tar);
            $copy->buildFromDirectory($work);
            $copy->compress(Phar::GZ);
            unset($copy);
            File::delete($tar);
            chmod($tar . '.gz', 0600);

            return $tar . '.gz';
        } finally {
            File::deleteDirectory($work);
        }
    }
}
