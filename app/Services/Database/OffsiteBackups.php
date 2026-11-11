<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DatabaseType;
use App\Enums\PlanFeature;
use App\Exceptions\OffsiteDownloadFailed;
use App\Exceptions\OffsiteListFailed;
use App\Exceptions\OffsiteUploadFailed;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Services\Billing\TeamBilling;
use App\Services\Deployment\LogSanitizer;
use App\Services\SSH\SSHConnection;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OffsiteBackups
{
    public const URL_MINUTES = 60;

    public const UPLOAD_SECONDS = 1140;

    public const SINGLE_UPLOAD_LIMIT = 5 * 1024 ** 3;

    public const KEEP_DUMPS = 3;

    public const LIST_PAGES = 5;

    public const LIST_SECONDS = 10;

    public function __construct(private readonly LogSanitizer $sanitizer) {}

    public function objectKey(BackupDestination $destination, ManagedDatabase $database, string $dumpPath): string
    {
        $names = array_map(
            fn(string $name): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $name),
            [(string) $database->team?->slug, (string) $database->server?->name, (string) $database->name, basename($dumpPath)],
        );

        return implode('/', array_filter([trim((string) $destination->prefix, '/'), ...$names], fn(string $part): bool => $part !== ''));
    }

    public function covers(ManagedDatabase $database): bool
    {
        $team = $database->team;

        return in_array($database->type, [DatabaseType::Mysql, DatabaseType::Postgresql], true)
            && $team?->backupDestination !== null
            && app(TeamBilling::class)->status($team)->allows(PlanFeature::OffsiteBackups);
    }

    public function presignedPut(BackupDestination $destination, string $key): string
    {
        return $this->presigned($destination, 'PutObject', ['Key' => $key]);
    }

    public function presignedGet(BackupDestination $destination, string $key): string
    {
        return $this->presigned($destination, 'GetObject', ['Key' => $key]);
    }

    /**
     * @return list<array{key: string, size: int, stamp: string}>
     */
    public function listed(BackupDestination $destination, ManagedDatabase $database): array
    {
        $prefix = dirname($this->objectKey($destination, $database, 'x')) . '/';
        $pattern = '/^' . preg_quote($prefix . $database->name, '/') . '-(\d{14})\.sql\.gz$/';
        $objects = [];
        $token = null;

        for ($page = 0; $page < self::LIST_PAGES; $page++) {
            $response = Http::timeout(self::LIST_SECONDS)->get($this->presigned($destination, 'ListObjectsV2', ['Prefix' => $prefix, ...($token === null ? [] : ['ContinuationToken' => $token])]));
            $xml = @simplexml_load_string($response->body());

            if (!$response->successful() || $xml === false || $xml->getName() !== 'ListBucketResult') {
                throw OffsiteListFailed::refused($xml !== false ? (string) $xml->Message : null);
            }

            foreach ($xml->Contents as $object) {
                if (preg_match($pattern, (string) $object->Key, $match) === 1) {
                    $objects[] = ['key' => (string) $object->Key, 'size' => (int) $object->Size, 'stamp' => $match[1]];
                }
            }

            $token = (string) $xml->NextContinuationToken;

            if ((string) $xml->IsTruncated !== 'true' || $token === '') {
                break;
            }
        }

        return $objects;
    }

    public function fetch(SSHConnection $connection, BackupDestination $destination, string $key, string $to, int $size): void
    {
        try {
            $url = $this->presignedGet($destination, $key);
        } catch (Throwable) {
            throw OffsiteDownloadFailed::noAddress();
        }

        $config = trim($connection->execute('mktemp', sudo: true)->throw()->output);
        $target = escapeshellarg($to);

        try {
            $connection->uploadContent('url = "' . $url . '"' . "\n", $config);

            $result = $connection->execute(
                'curl --fail --silent --show-error --max-time ' . self::UPLOAD_SECONDS . ' --config ' . escapeshellarg($config) . ' -o ' . $target,
                sudo: true,
                timeout: self::UPLOAD_SECONDS + 60,
            );

            if ($result->failed()) {
                $connection->execute('rm -f ' . $target, sudo: true);

                throw OffsiteDownloadFailed::rejected($this->sanitizer->sanitize($result->output));
            }

            $received = (int) trim($connection->execute('stat -c %s ' . $target, sudo: true)->output);

            if ($received !== $size) {
                $connection->execute('rm -f ' . $target, sudo: true);

                throw OffsiteDownloadFailed::short($received, $size);
            }

            $connection->execute('chmod 600 ' . $target, sudo: true)->throw();
        } finally {
            $connection->execute('rm -f ' . escapeshellarg($config), sudo: true);
        }
    }

    /**
     * @param  array<string, string>  $arguments
     */
    private function presigned(BackupDestination $destination, string $command, array $arguments): string
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $destination->region,
            'credentials' => ['key' => (string) $destination->access_key_id, 'secret' => (string) $destination->secret_access_key],
            'use_path_style_endpoint' => $destination->path_style,
            'use_aws_shared_config_files' => false,
        ] + ($destination->endpoint ? ['endpoint' => $destination->endpoint] : []));

        return (string) $client->createPresignedRequest(
            $client->getCommand($command, ['Bucket' => $destination->bucket, ...$arguments]),
            '+' . self::URL_MINUTES . ' minutes',
        )->getUri();
    }

    public function send(SSHConnection $connection, ManagedDatabase $database, string $dumpPath, BackupDestination $destination): string
    {
        $stat = $connection->execute('stat -c %s ' . escapeshellarg($dumpPath), sudo: true);

        if ($stat->failed()) {
            throw OffsiteUploadFailed::dumpMissing(basename($dumpPath), (string) $database->server?->name);
        }

        $size = (int) trim($stat->output);

        if ($size > self::SINGLE_UPLOAD_LIMIT) {
            throw OffsiteUploadFailed::tooLarge($size);
        }

        $key = $this->objectKey($destination, $database, $dumpPath);
        $url = $this->uploadAddress($destination, $key);
        $config = trim($connection->execute('mktemp', sudo: true)->throw()->output);

        try {
            $connection->uploadContent('url = "' . $url . '"' . "\n", $config);

            $result = $connection->execute(
                'curl --fail --silent --show-error --max-time ' . self::UPLOAD_SECONDS . ' --config ' . escapeshellarg($config) . ' --upload-file ' . escapeshellarg($dumpPath),
                sudo: true,
                timeout: self::UPLOAD_SECONDS + 60,
            );

            if ($result->failed()) {
                throw OffsiteUploadFailed::rejected($this->sanitizer->sanitize($result->output));
            }
        } finally {
            $connection->execute('rm -f ' . escapeshellarg($config), sudo: true);
        }

        return $key;
    }

    private function uploadAddress(BackupDestination $destination, string $key): string
    {
        try {
            return $this->presignedPut($destination, $key);
        } catch (Throwable) {
            throw OffsiteUploadFailed::noAddress();
        }
    }

    public function pruneDumps(SSHConnection $connection, ManagedDatabase $database, string $dumpPath): void
    {
        $pattern = '/' . preg_quote((string) $database->name, '/') . '-[0-9]{14}\.sql\.gz$';

        $connection->execute(
            'ls -1 ' . escapeshellarg(dirname($dumpPath)) . '/*.sql.gz 2>/dev/null | grep -E ' . escapeshellarg($pattern)
            . ' | sort -r | tail -n +' . (self::KEEP_DUMPS + 1) . ' | while read -r dump; do rm -f -- "$dump"; done',
            sudo: true,
        );
    }
}
