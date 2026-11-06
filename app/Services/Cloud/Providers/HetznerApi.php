<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Providers;

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Enums\CloudRefusal;
use App\Services\Cloud\CloudAccount;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\SSH\HostKeyFingerprint;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use LogicException;

final class HetznerApi extends HttpCloudApi implements CloudApi
{
    private const PAGE_SIZE = 50;

    public function type(): CloudProviderType
    {
        return CloudProviderType::Hetzner;
    }

    public function verify(): CloudAccount
    {
        $this->getJson('/servers', ['per_page' => 1]);

        return new CloudAccount(null);
    }

    public function regions(): Collection
    {
        return $this->pages('/locations', [], 'locations')
            ->filter(fn(mixed $location): bool => is_array($location) && self::text($location['name'] ?? null) !== null)
            ->map(function (array $location): CloudRegion {
                $name = (string) $location['name'];
                $city = self::text($location['city'] ?? null);

                return new CloudRegion($name, $city === null ? $name : "{$city} ({$name})", self::text($location['country'] ?? null));
            })
            ->values();
    }

    public function sizes(): Collection
    {
        return $this->pages('/server_types', ['per_page' => self::PAGE_SIZE], 'server_types')
            ->filter(fn(mixed $type): bool => is_array($type)
                && self::text($type['name'] ?? null) !== null
                && self::text($type['architecture'] ?? null) !== null
                && self::hasNumbers($type, 'cores', 'memory', 'disk')
                && !self::deprecated($type))
            ->map(fn(array $type): CloudSize => $this->size($type))
            ->values();
    }

    public function images(): Collection
    {
        return $this->pages('/images', ['type' => 'system', 'status' => 'available', 'per_page' => self::PAGE_SIZE], 'images')
            ->filter(fn(mixed $image): bool => is_array($image) && self::text($image['name'] ?? null) !== null && !self::deprecated($image))
            ->groupBy('name')
            ->map(function (Collection $group): CloudImage {
                $image = $group->first();

                return new CloudImage(
                    (string) $image['name'],
                    self::text($image['os_flavor'] ?? null) ?? '',
                    self::text($image['os_version'] ?? null) ?? '',
                    $group->map(fn(array $entry): ?string => self::text($entry['architecture'] ?? null))->filter()->unique()->values()->all(),
                );
            })
            ->values();
    }

    public function uploadsKeys(): bool
    {
        return true;
    }

    public function uploadKey(string $name, string $publicKey): string
    {
        $fingerprint = HostKeyFingerprint::md5FromPublicKey($publicKey);

        if ($fingerprint !== null) {
            $held = $this->firstMatch(
                $this->listed($this->getJson('/ssh_keys', ['fingerprint' => $fingerprint]), 'ssh_keys'),
                fn(array $key): bool => self::digest($key['fingerprint'] ?? null) === self::digest($fingerprint),
            );

            if ($held !== null) {
                return $this->identifier($held['id'] ?? null);
            }
        }

        $response = $this->postJson('/ssh_keys', [
            'name' => $name,
            'public_key' => $publicKey,
            'labels' => ['managed-by' => 'coresmith'],
        ]);

        return $this->identifier($response->json('ssh_key.id'));
    }

    public function deleteKey(string $keyId): void
    {
        $this->deleteOrGone("/ssh_keys/{$this->segment($keyId)}");
    }

    public function create(CloudServerSpec $spec): CloudInstance
    {
        if ($spec->keyId === null) {
            throw new LogicException('Hetzner Cloud needs the id of an uploaded key to create a server.');
        }

        $response = $this->postJson('/servers', [
            'name' => $spec->name,
            'server_type' => $spec->size,
            'image' => $spec->image,
            'location' => $spec->region,
            'ssh_keys' => [(int) $spec->keyId],
            'labels' => ['coresmith' => $spec->tag, 'managed-by' => 'coresmith'],
            'start_after_create' => true,
            'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
        ]);

        return $this->instance($response->json('server'));
    }

    public function find(string $instanceId): ?CloudInstance
    {
        $response = $this->getJsonOrNull("/servers/{$this->segment($instanceId)}");

        return $response === null ? null : $this->instance($response->json('server'));
    }

    public function findTagged(string $tag): ?CloudInstance
    {
        $server = $this->firstMatch(
            $this->listed($this->getJson('/servers', ['label_selector' => "coresmith={$tag}"]), 'servers'),
            fn(array $server): bool => data_get($server, 'labels.coresmith') === $tag,
        );

        return $server === null ? null : $this->instance($server);
    }

    public function reboot(string $instanceId): void
    {
        $this->postJson("/servers/{$this->segment($instanceId)}/actions/reboot");
    }

    public function powerOff(string $instanceId): void
    {
        $this->postJson("/servers/{$this->segment($instanceId)}/actions/shutdown");
    }

    public function powerOn(string $instanceId): void
    {
        $this->postJson("/servers/{$this->segment($instanceId)}/actions/poweron");
    }

    public function resize(string $instanceId, string $size): void
    {
        $this->postJson("/servers/{$this->segment($instanceId)}/actions/change_type", [
            'server_type' => $size,
            'upgrade_disk' => false,
        ]);
    }

    public function destroy(string $instanceId): void
    {
        $this->deleteOrGone("/servers/{$this->segment($instanceId)}");
    }

    protected function message(Response $response): ?string
    {
        return self::text($response->json('error.message'));
    }

    protected function classify(Response $response): ?CloudFailure
    {
        return match (self::text($response->json('error.code'))) {
            'protected', 'resource_limit_exceeded', 'uniqueness_error' => CloudFailure::Refused,
            'conflict' => CloudFailure::Busy,
            'token_readonly' => CloudFailure::Forbidden,
            default => null,
        };
    }

    protected function refusal(Response $response): ?CloudRefusal
    {
        return match (self::text($response->json('error.code'))) {
            'uniqueness_error' => CloudRefusal::NameTaken,
            'resource_limit_exceeded' => CloudRefusal::LimitReached,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, mixed>
     */
    private function pages(string $path, array $query, string $key): Collection
    {
        return $this->paginate($path, $query, $key, function (Response $response) use ($query): ?array {
            $next = $response->json('meta.pagination.next_page');

            return is_int($next) ? [...$query, 'page' => $next] : null;
        });
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function size(array $type): CloudSize
    {
        $prices = [];

        foreach ((array) ($type['prices'] ?? []) as $price) {
            $location = data_get($price, 'location');
            $monthly = data_get($price, 'price_monthly.net');

            if (is_string($location) && is_numeric($monthly)) {
                $prices[$location] = CloudPrice::fromNumber($monthly, 'EUR');
            }
        }

        $locations = $type['locations'] ?? null;

        return new CloudSize(
            (string) $type['name'],
            (int) $type['cores'],
            (int) round((float) $type['memory'] * 1024),
            (int) $type['disk'],
            (string) $type['architecture'],
            null,
            $prices,
            is_array($locations) ? $this->offeredIn($locations) : array_keys($prices),
        );
    }

    /**
     * @param  array<array-key, mixed>  $locations
     * @return list<string>
     */
    private function offeredIn(array $locations): array
    {
        return (new Collection($locations))
            ->filter(fn(mixed $location): bool => is_array($location)
                && self::text($location['name'] ?? null) !== null
                && ($location['available'] ?? null) !== false
                && !self::deprecated($location))
            ->map(fn(array $location): string => (string) $location['name'])
            ->values()
            ->all();
    }

    private function instance(mixed $server): CloudInstance
    {
        return new CloudInstance(
            $this->identifier(data_get($server, 'id')),
            self::state(data_get($server, 'status')),
            self::publicIpv4(data_get($server, 'public_net.ipv4.ip')),
            self::text(data_get($server, 'server_type.name')),
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function deprecated(array $entry): bool
    {
        $flag = $entry['deprecated'] ?? null;
        $notice = $entry['deprecation'] ?? null;

        return ($notice !== null && self::hasPassed(data_get($notice, 'unavailable_after'), true))
            || (is_string($flag) && $flag !== '' && self::hasPassed($flag, true))
            || ($flag === true && $notice === null);
    }

    private static function digest(mixed $fingerprint): ?string
    {
        return is_string($fingerprint) ? strtolower(str_replace(':', '', $fingerprint)) : null;
    }

    private static function state(mixed $status): CloudInstanceState
    {
        return match ($status) {
            'initializing', 'starting' => CloudInstanceState::Starting,
            'running' => CloudInstanceState::Running,
            'off' => CloudInstanceState::Stopped,
            'stopping', 'migrating', 'rebuilding' => CloudInstanceState::Busy,
            'deleting' => CloudInstanceState::Deleting,
            default => CloudInstanceState::Unknown,
        };
    }
}
