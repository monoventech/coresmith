<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\CloudProviderType;
use App\Enums\DatabaseType;
use App\Enums\Edition;
use App\Enums\PackageSource;
use App\Enums\SourceProviderType;
use App\Services\Database\S3DestinationInput;
use App\Services\Network\OwnAddress;
use App\Services\Network\PublicAddress;
use App\Services\SafeName;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

final class MoveChecks
{
    public const LIMIT = 10;

    public const TEAMMATES = 50;

    private const FINGERPRINT = '/^SHA256:[A-Za-z0-9+\/]{43}$/D';

    private const PART = "Part of the team in the file isn't valid. Download the move file again.";

    public function __construct(private readonly OwnAddress $own) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function problems(array $payload): array
    {
        $cloud = Edition::current()->isCloud();
        $servers = $this->records($payload, 'servers');
        $applications = $this->records($payload, 'applications');
        $refs = [
            'servers' => $this->refs($servers),
            'applications' => $this->refs($applications),
            'projects' => $this->refs($this->records($payload, 'projects')),
            'cloud_providers' => $this->refs($this->records($payload, 'cloud_providers')),
            'source_providers' => $this->refs($this->records($payload, 'source_providers')),
        ];

        $problems = [
            $this->passes(['team' => $payload['team'] ?? null], ['team' => ['required', 'array'], 'team.name' => ['required', 'string', 'max:255']]) ? null : self::PART,
            $cloud && count($this->records($payload, 'members')) > self::TEAMMATES ? 'This team has more than ' . self::TEAMMATES . ' teammates to invite. Move it with fewer, then invite the rest here.' : null,
            ...array_map(fn(array $server): ?string => $this->server($server, $refs, $cloud), $servers),
            ...array_map(fn(array $application): ?string => $this->passes($application, $this->applicationRules($refs)) ? null : $this->named($application['name'] ?? null, 'An app') . "'s settings in the file aren't valid. Download the move file again.", $applications),
            ...array_map(fn(array $database): ?string => $this->passes($database, $this->databaseRules($refs)) ? null : 'The ' . $this->named($database['name'] ?? null, 'moved') . " database's settings in the file aren't valid. Download the move file again.", $this->records($payload, 'databases')),
            $this->backups($payload['backup_destination'] ?? null),
            ...array_map(fn(array $record): ?string => $this->passes($record, ['ref' => ['required'], 'name' => ['required', 'string', 'max:255']]) ? null : self::PART, $this->records($payload, 'projects')),
            ...array_map(fn(array $record): ?string => $this->passes($record, ['ref' => ['required'], 'type' => ['required', Rule::enum(CloudProviderType::class)], 'name' => ['required', 'string', 'max:255'], 'api_token' => ['required', 'string']]) ? null : self::PART, $this->records($payload, 'cloud_providers')),
            ...array_map(fn(array $record): ?string => $this->passes($record, ['ref' => ['required'], 'custom_host' => ['nullable', 'string', $this->domain(...)]]) ? null : self::PART, $this->known($this->records($payload, 'source_providers'), 'type', SourceProviderType::class)),
            ...array_map(fn(array $record): ?string => $this->passes($record, ['application' => ['required', Rule::in($refs['applications'])], 'secret' => ['required', 'string', 'max:255'], 'provider' => ['required', 'string', 'max:32']]) ? null : self::PART, $this->records($payload, 'webhook_endpoints')),
            ...array_map(fn(array $record): ?string => $this->passes($record, $this->packageRules($refs)) ? null : self::PART, $this->known($this->records($payload, 'package_credentials'), 'source', PackageSource::class)),
        ];

        return array_slice(array_values(array_filter($problems, fn(?string $problem): bool => $problem !== null)), 0, self::LIMIT);
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, list<string>>  $refs
     */
    private function server(array $server, array $refs, bool $cloud): ?string
    {
        $name = $this->named($server['name'] ?? null, 'A server');
        $rules = [
            'ref' => ['required'],
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'ssh_user' => ['required', 'string', 'regex:' . SafeName::SYSTEM_USER_PATTERN],
            'ssh_private_key' => ['required', 'string', $this->privateKey(...)],
            'ssh_public_key' => ['required', 'string'],
            'host_key_fingerprint' => ['required', 'string', 'regex:' . self::FINGERPRINT],
            'cloud_provider' => ['nullable', Rule::in($refs['cloud_providers'])],
            'tags' => ['nullable', 'array'],
            'installed_runtimes' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ];

        if (!$this->passes($server, $rules)) {
            return "{$name}'s connection details in the file aren't valid. Download the move file again.";
        }

        $address = (string) $server['ip_address'];

        if ($cloud && (!PublicAddress::is($address) || $this->own->is($address))) {
            return "{$name} is at {$address}, which Coresmith Cloud can't reach. Give it a public address before you move the team.";
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $refs
     * @return array<string, list<mixed>>
     */
    private function applicationRules(array $refs): array
    {
        return [
            'ref' => ['required'],
            'server' => ['required', Rule::in($refs['servers'])],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'regex:' . SafeName::SLUG_PATTERN],
            'system_user' => ['required', 'string', 'regex:' . SafeName::SYSTEM_USER_PATTERN],
            'type' => ['required', Rule::enum(ApplicationType::class)],
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
            'domain' => ['nullable', 'string', $this->domain(...)],
            'additional_domains' => ['nullable', 'array'],
            'additional_domains.*' => ['string', $this->domain(...)],
            'base_port' => ['nullable', 'integer', 'between:1,65535'],
            'source_provider' => ['nullable', Rule::in($refs['source_providers'])],
            'project' => ['nullable', Rule::in($refs['projects'])],
            'tags' => ['nullable', 'array'],
        ];
    }

    /**
     * @param  array<string, list<string>>  $refs
     * @return array<string, list<mixed>>
     */
    private function databaseRules(array $refs): array
    {
        return [
            'server' => ['required', Rule::in($refs['servers'])],
            'type' => ['required', Rule::enum(DatabaseType::class)],
            'name' => ['required', 'string', 'regex:' . SafeName::DATABASE_PATTERN],
            'db_user' => ['nullable', 'string', 'regex:' . SafeName::DATABASE_PATTERN],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'project' => ['nullable', Rule::in($refs['projects'])],
        ];
    }

    /**
     * @param  array<string, list<string>>  $refs
     * @return array<string, list<mixed>>
     */
    private function packageRules(array $refs): array
    {
        return [
            'target' => ['required', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'max:214'],
            'username' => ['nullable', 'string', 'max:255', 'regex:/^\S+\z/D'],
            'secret' => ['required', 'string', 'max:4096', 'regex:/^\S+\z/D'],
            'project' => ['nullable', Rule::in($refs['projects'])],
        ];
    }

    private function backups(mixed $destination): ?string
    {
        if ($destination === null) {
            return null;
        }

        if (!is_array($destination)) {
            return self::PART;
        }

        $validator = Validator::make($destination, S3DestinationInput::rules(null), S3DestinationInput::messages());

        return $validator->fails() ? "The team's S3 backup settings in the file aren't valid. " . $validator->errors()->first() : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, list<mixed>>  $rules
     */
    private function passes(array $record, array $rules): bool
    {
        return !Validator::make($record, $rules)->fails();
    }

    private function privateKey(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            PublicKeyLoader::loadPrivateKey((string) $value);
        } catch (Throwable) {
            $fail('unreadable');
        }
    }

    private function domain(string $attribute, mixed $value, Closure $fail): void
    {
        if (!SafeName::isDomain(is_string($value) ? $value : null)) {
            $fail('not a domain');
        }
    }

    private function named(mixed $name, string $fallback): string
    {
        $text = is_string($name) ? Str::of($name)->squish()->limit(60)->toString() : '';

        return $text === '' ? $fallback : $text;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  class-string<\BackedEnum>  $enum
     * @return list<array<string, mixed>>
     */
    private function known(array $records, string $key, string $enum): array
    {
        return array_values(array_filter($records, fn(array $record): bool => $enum::tryFrom((string) ($record[$key] ?? '')) !== null));
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<string>
     */
    private function refs(array $records): array
    {
        return array_values(array_map(fn(array $record): string => (string) ($record['ref'] ?? ''), $records));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function records(array $payload, string $key): array
    {
        return array_values(array_filter(is_array($payload[$key] ?? null) ? $payload[$key] : [], 'is_array'));
    }
}
