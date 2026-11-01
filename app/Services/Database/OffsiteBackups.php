<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Exceptions\OffsiteUploadFailed;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Services\Deployment\LogSanitizer;
use App\Services\SSH\SSHConnection;
use Aws\S3\S3Client;

final class OffsiteBackups
{
    public const URL_MINUTES = 60;

    public const UPLOAD_SECONDS = 1140;

    public const SINGLE_UPLOAD_LIMIT = 5 * 1024 ** 3;

    public const KEEP_DUMPS = 3;

    public function __construct(private readonly LogSanitizer $sanitizer) {}

    public function objectKey(BackupDestination $destination, ManagedDatabase $database, string $dumpPath): string
    {
        $names = array_map(
            fn(string $name): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $name),
            [(string) $database->team?->slug, (string) $database->server?->name, (string) $database->name, basename($dumpPath)],
        );

        return implode('/', array_filter([trim((string) $destination->prefix, '/'), ...$names], fn(string $part): bool => $part !== ''));
    }

    public function presignedPut(BackupDestination $destination, string $key): string
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $destination->region,
            'credentials' => ['key' => (string) $destination->access_key_id, 'secret' => (string) $destination->secret_access_key],
            'use_path_style_endpoint' => $destination->path_style,
            'use_aws_shared_config_files' => false,
        ] + ($destination->endpoint ? ['endpoint' => $destination->endpoint] : []));

        return (string) $client->createPresignedRequest(
            $client->getCommand('PutObject', ['Bucket' => $destination->bucket, 'Key' => $key]),
            '+' . self::URL_MINUTES . ' minutes',
        )->getUri();
    }

    public function send(SSHConnection $connection, ManagedDatabase $database, string $dumpPath, BackupDestination $destination): string
    {
        $size = (int) trim($connection->execute('stat -c %s ' . escapeshellarg($dumpPath), sudo: true)->throw()->output);

        if ($size > self::SINGLE_UPLOAD_LIMIT) {
            throw OffsiteUploadFailed::tooLarge($size);
        }

        $key = $this->objectKey($destination, $database, $dumpPath);
        $config = trim($connection->execute('mktemp')->throw()->output);

        try {
            $connection->uploadContent('url = "' . $this->presignedPut($destination, $key) . '"' . "\n", $config);

            $result = $connection->execute(
                'curl --fail --silent --show-error --max-time ' . self::UPLOAD_SECONDS . ' --config ' . escapeshellarg($config) . ' --upload-file ' . escapeshellarg($dumpPath),
                sudo: true,
                timeout: self::UPLOAD_SECONDS + 60,
            );

            if ($result->failed()) {
                throw OffsiteUploadFailed::rejected($this->sanitizer->sanitize($result->output));
            }
        } finally {
            $connection->execute('rm -f ' . escapeshellarg($config));
        }

        return $key;
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
