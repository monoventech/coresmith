<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

final class MoveColumns
{
    public const SERVERS = [
        'name', 'ip_address', 'ssh_port', 'ssh_user', 'ssh_private_key', 'ssh_public_key', 'host_key_fingerprint',
        'os_name', 'os_version', 'provisioning_phase', 'installed_runtimes', 'meta', 'audit_logging_enabled',
        'environment', 'region', 'booted_at', 'provider_server_id', 'provider_size', 'provider_image',
        'provider_monthly_cost', 'provider_currency',
    ];

    public const APPLICATIONS = [
        'name', 'slug', 'type', 'js_runtime', 'framework', 'domain', 'repository_url', 'repository_branch',
        'repository_provider', 'custom_blueprint', 'env_variables', 'system_user', 'base_port', 'php_version',
        'node_version', 'ruby_version', 'bun_version', 'deno_version', 'python_version', 'directory', 'web_directory',
        'database_type', 'run_migrations', 'max_upload_size_mb', 'status', 'quick_deploy', 'power_state',
        'app_size', 'idle_timeout_minutes', 'ssr_port', 'toggles', 'processes', 'cron_jobs',
        'additional_domains', 'deploy_key_private', 'deploy_key_public', 'current_release', 'runtime_pins',
        'stack', 'repository_id', 'repository_head', 'repository_head_source', 'repository_head_at',
        'repository_pushed_at',
    ];

    public const DATABASES = [
        'type', 'name', 'db_user', 'db_password', 'credentials_shown_at', 'host', 'port', 'status', 'privilege_level', 'extensions',
        'is_external', 'last_offsite_at', 'last_offsite_key', 'last_offsite_error',
    ];

    public const CLOUD_PROVIDERS = ['type', 'name', 'account_label', 'api_token', 'verified_at'];

    public const BACKUP_DESTINATION = ['endpoint', 'region', 'bucket', 'prefix', 'access_key_id', 'secret_access_key', 'path_style', 'last_run_at'];

    public const WEBHOOK_ENDPOINTS = ['secret', 'provider', 'active'];

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    public static function read(Model $model, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = self::plain($model->getAttributeValue($column));
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    public static function pick(array $record, array $columns): array
    {
        return array_intersect_key($record, array_flip($columns));
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            default => $value,
        };
    }
}
