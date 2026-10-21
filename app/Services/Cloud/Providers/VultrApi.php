<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Providers;

use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
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

final class VultrApi extends HttpCloudApi implements CloudApi
{
    protected const ID_PATTERN = '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD';

    private const PAGE_SIZE = 500;

    private const PLAN_TYPES = ['vc2', 'vhf', 'vhp', 'voc'];

    private const FAMILIES = ['ubuntu', 'debian'];

    private const SYSTEM_ID = '/\A[1-9]\d*\z/D';

    private const SYSTEM_VERSION = '/(\d+(?:\.\d+)?)/D';

    public function type(): CloudProviderType
    {
        return CloudProviderType::Vultr;
    }

    public function verify(): CloudAccount
    {
        $account = $this->getJson('/account')->json('account');

        if (!is_array($account) || array_is_list($account)) {
            throw $this->unusable();
        }

        return new CloudAccount(self::text($account['email'] ?? null));
    }

    public function regions(): Collection
    {
        return $this->pages('/regions', 'regions')
            ->filter(fn(mixed $region): bool => is_array($region) && self::text($region['id'] ?? null) !== null)
            ->map(function (array $region): CloudRegion {
                $id = (string) $region['id'];
                $city = self::text($region['city'] ?? null);

                return new CloudRegion($id, $city === null ? $id : "{$city} ({$id})", self::text($region['country'] ?? null));
            })
            ->values();
    }

    public function sizes(): Collection
    {
        return $this->pages('/plans', 'plans')
            ->filter(fn(mixed $plan): bool => is_array($plan)
                && self::text($plan['id'] ?? null) !== null
                && in_array($plan['type'] ?? null, self::PLAN_TYPES, true)
                && self::hasNumbers($plan, 'vcpu_count', 'ram', 'disk'))
            ->map(fn(array $plan): CloudSize => self::size($plan))
            ->values();
    }

    public function images(): Collection
    {
        return $this->pages('/os', 'os')
            ->map(fn(mixed $system): ?CloudImage => is_array($system) ? self::image($system) : null)
            ->filter()
            ->values();
    }

    public function uploadsKeys(): bool
    {
        return true;
    }

    public function uploadKey(string $name, string $publicKey): string
    {
        $fingerprint = HostKeyFingerprint::md5FromPublicKey($publicKey);
        $held = $fingerprint === null ? null : $this->heldKey($fingerprint);

        if ($held !== null) {
            return $held;
        }

        $response = $this->postJson('/ssh-keys', ['name' => $name, 'ssh_key' => $publicKey]);

        return $this->identifier($response->json('ssh_key.id'));
    }

    public function deleteKey(string $keyId): void
    {
        $this->deleteOrGone("/ssh-keys/{$this->segment($keyId)}");
    }

    public function create(CloudServerSpec $spec): CloudInstance
    {
        if ($spec->keyId === null) {
            throw new LogicException('Vultr needs the id of an uploaded key to create an instance.');
        }

        $response = $this->postJson('/instances', [
            'region' => $spec->region,
            'plan' => $spec->size,
            'os_id' => (int) $spec->image,
            'label' => $spec->name,
            'hostname' => $spec->name,
            'sshkey_id' => [$spec->keyId],
            'tags' => ['coresmith', $spec->tag],
            'enable_ipv6' => true,
            'backups' => 'disabled',
        ]);

        return $this->instance($response->json('instance'));
    }

    public function find(string $instanceId): ?CloudInstance
    {
        $response = $this->getJsonOrNull("/instances/{$this->segment($instanceId)}");

        return $response === null ? null : $this->instance($response->json('instance'));
    }

    public function findTagged(string $tag): ?CloudInstance
    {
        $instance = $this->firstMatch(
            $this->listed($this->getJson('/instances', ['tag' => $tag]), 'instances'),
            fn(array $instance): bool => self::carries($instance, $tag),
        );

        return $instance === null ? null : $this->instance($instance);
    }

    public function reboot(string $instanceId): void
    {
        $this->postJson("/instances/{$this->segment($instanceId)}/reboot");
    }

    public function resizeNeedsPowerOff(): bool
    {
        return false;
    }

    public function powerOff(string $instanceId): void
    {
        $this->postJson("/instances/{$this->segment($instanceId)}/halt");
    }

    public function powerOn(string $instanceId): void
    {
        $this->postJson("/instances/{$this->segment($instanceId)}/start");
    }

    public function resize(string $instanceId, string $size): void
    {
        $this->patchJson("/instances/{$this->segment($instanceId)}", ['plan' => $size]);
    }

    public function destroy(string $instanceId): void
    {
        $this->deleteOrGone("/instances/{$this->segment($instanceId)}");
    }

    protected function message(Response $response): ?string
    {
        return self::text($response->json('error'));
    }

    /**
     * @return Collection<int, mixed>
     */
    private function pages(string $path, string $key): Collection
    {
        $query = ['per_page' => self::PAGE_SIZE];

        return $this->paginate($path, $query, $key, function (Response $response) use ($query): ?array {
            $cursor = self::text($response->json('meta.links.next'));

            return $cursor === null ? null : [...$query, 'cursor' => $cursor];
        });
    }

    private function heldKey(string $fingerprint): ?string
    {
        foreach ($this->pages('/ssh-keys', 'ssh_keys') as $key) {
            if (is_array($key) && HostKeyFingerprint::md5FromPublicKey(self::text($key['ssh_key'] ?? null) ?? '') === $fingerprint) {
                return $this->identifier($key['id'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private static function size(array $plan): CloudSize
    {
        $monthly = $plan['monthly_cost'] ?? null;

        return new CloudSize(
            (string) $plan['id'],
            (int) $plan['vcpu_count'],
            (int) $plan['ram'],
            (int) $plan['disk'],
            'x86',
            is_numeric($monthly) ? CloudPrice::fromNumber($monthly, 'USD') : null,
            [],
            self::locations($plan['locations'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $system
     */
    private static function image(array $system): ?CloudImage
    {
        $id = self::systemId($system['id'] ?? null);
        $family = self::text($system['family'] ?? null);
        $name = self::text($system['name'] ?? null);

        if ($id === null || $family === null || $name === null || ($system['arch'] ?? null) !== 'x64') {
            return null;
        }

        if (!in_array($family, self::FAMILIES, true) || preg_match(self::SYSTEM_VERSION, $name, $version) !== 1) {
            return null;
        }

        return new CloudImage($id, $family, $version[1], ['x86']);
    }

    private static function systemId(mixed $id): ?string
    {
        $text = is_int($id) ? (string) $id : $id;

        return is_string($text) && preg_match(self::SYSTEM_ID, $text) === 1 ? $text : null;
    }

    /**
     * @return list<string>
     */
    private static function locations(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, fn(mixed $value): bool => self::text($value) !== null))
            : [];
    }

    private function instance(mixed $instance): CloudInstance
    {
        return new CloudInstance(
            $this->identifier(data_get($instance, 'id')),
            self::state($instance),
            self::publicIpv4(data_get($instance, 'main_ip')),
            self::text(data_get($instance, 'plan')),
        );
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private static function carries(array $instance, string $tag): bool
    {
        $tags = $instance['tags'] ?? null;

        return is_array($tags) && in_array($tag, $tags, true);
    }

    private static function state(mixed $instance): CloudInstanceState
    {
        $status = data_get($instance, 'status');
        $power = data_get($instance, 'power_status');

        return match (true) {
            $status === 'pending' => CloudInstanceState::Starting,
            $status === 'resizing' => CloudInstanceState::Busy,
            $power === 'stopped' => CloudInstanceState::Stopped,
            $status === 'active' && $power === 'running' => data_get($instance, 'server_status') === 'ok'
                ? CloudInstanceState::Running
                : CloudInstanceState::Starting,
            default => CloudInstanceState::Unknown,
        };
    }
}
