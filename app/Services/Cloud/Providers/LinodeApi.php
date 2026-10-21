<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Providers;

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Services\Cloud\CloudAccount;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Contracts\CloudApi;
use DateTimeZone;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;

final class LinodeApi extends HttpCloudApi implements CloudApi
{
    private const PAGE_SIZE = 500;

    private const PLAN_CLASSES = ['nanode', 'standard', 'dedicated', 'highmem', 'premium'];

    private const IMAGE_ID = '/^linode\/(ubuntu|debian)(\d+(?:\.\d+)?)$/D';

    private const SCOPE_SEPARATOR = '/[\s,]+/D';

    private const WRITE_SCOPE = 'linodes:read_write';

    private const ROOT_PASSWORD_LENGTH = 48;

    public function type(): CloudProviderType
    {
        return CloudProviderType::Linode;
    }

    public function verify(): CloudAccount
    {
        $response = $this->getJson('/profile');
        $username = self::text($response->json('username'));

        if ($username === null) {
            throw $this->unusable();
        }

        if (!self::mayWriteLinodes($response->header('X-OAuth-Scopes'))) {
            throw new CloudProviderException(CloudFailure::Forbidden, $this->type());
        }

        return new CloudAccount($username);
    }

    public function regions(): Collection
    {
        return $this->pages('/regions')
            ->filter(fn(mixed $region): bool => is_array($region)
                && self::text($region['id'] ?? null) !== null
                && self::hostsLinodes($region))
            ->map(function (array $region): CloudRegion {
                $id = (string) $region['id'];
                $label = self::text($region['label'] ?? null);
                $country = self::text($region['country'] ?? null);

                return new CloudRegion($id, $label === null ? $id : "{$label} ({$id})", $country === null ? null : strtoupper($country));
            })
            ->values();
    }

    public function sizes(): Collection
    {
        return $this->pages('/linode/types')
            ->filter(fn(mixed $type): bool => is_array($type)
                && self::text($type['id'] ?? null) !== null
                && in_array($type['class'] ?? null, self::PLAN_CLASSES, true)
                && self::hasNumbers($type, 'vcpus', 'memory', 'disk'))
            ->map(fn(array $type): CloudSize => self::size($type))
            ->values();
    }

    public function images(): Collection
    {
        return $this->pages('/images', ['X-Filter' => self::filter(['is_public' => true])])
            ->map(fn(mixed $image): ?CloudImage => is_array($image) ? self::image($image) : null)
            ->filter()
            ->values();
    }

    public function uploadsKeys(): bool
    {
        return false;
    }

    public function uploadKey(string $name, string $publicKey): string
    {
        throw new LogicException('Linode takes the public key with the instance, so there is no key to upload.');
    }

    public function deleteKey(string $keyId): void
    {
        throw new LogicException('Linode takes the public key with the instance, so there is no uploaded key to delete.');
    }

    public function create(CloudServerSpec $spec): CloudInstance
    {
        if (trim($spec->publicKey) === '') {
            throw new LogicException('Linode needs a public key to put on an instance.');
        }

        $response = $this->postJson('/linode/instances', [
            'region' => $spec->region,
            'type' => $spec->size,
            'image' => $spec->image,
            'label' => $spec->name,
            'root_pass' => Str::password(self::ROOT_PASSWORD_LENGTH),
            'authorized_keys' => [$spec->publicKey],
            'tags' => ['coresmith', $spec->tag],
            'booted' => true,
        ]);

        return $this->instance($response->json());
    }

    public function find(string $instanceId): ?CloudInstance
    {
        $response = $this->getJsonOrNull("/linode/instances/{$this->segment($instanceId)}");

        return $response === null ? null : $this->instance($response->json());
    }

    public function findTagged(string $tag): ?CloudInstance
    {
        $instance = $this->firstMatch(
            $this->listed($this->getJson('/linode/instances', [], ['X-Filter' => self::filter(['tags' => $tag])]), 'data'),
            fn(array $instance): bool => self::carries($instance, $tag),
        );

        return $instance === null ? null : $this->instance($instance);
    }

    public function reboot(string $instanceId): void
    {
        $this->postJson("/linode/instances/{$this->segment($instanceId)}/reboot");
    }

    public function resizeNeedsPowerOff(): bool
    {
        return false;
    }

    public function powerOff(string $instanceId): void
    {
        $this->postJson("/linode/instances/{$this->segment($instanceId)}/shutdown");
    }

    public function powerOn(string $instanceId): void
    {
        $this->postJson("/linode/instances/{$this->segment($instanceId)}/boot");
    }

    public function resize(string $instanceId, string $size): void
    {
        $this->postJson("/linode/instances/{$this->segment($instanceId)}/resize", [
            'type' => $size,
            'allow_auto_disk_resize' => false,
            'migration_type' => 'cold',
        ]);
    }

    public function destroy(string $instanceId): void
    {
        $this->deleteOrGone("/linode/instances/{$this->segment($instanceId)}");
    }

    protected function message(Response $response): ?string
    {
        $reason = self::text($response->json('errors.0.reason'));
        $field = self::text($response->json('errors.0.field'));

        if ($reason === null) {
            return null;
        }

        return $field === null ? $reason : "{$field}: {$reason}";
    }

    /**
     * @param  array<string, string>  $headers
     * @return Collection<int, mixed>
     */
    private function pages(string $path, array $headers = []): Collection
    {
        $query = ['page_size' => self::PAGE_SIZE];
        $page = 1;

        return $this->paginate($path, $query, 'data', function (Response $response) use (&$page, $query): ?array {
            $pages = $response->json('pages');

            return is_int($pages) && $page < $pages ? [...$query, 'page' => ++$page] : null;
        }, $headers);
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    private static function filter(array $conditions): string
    {
        return json_encode($conditions, JSON_THROW_ON_ERROR);
    }

    private static function mayWriteLinodes(string $scopeHeader): bool
    {
        $scopes = preg_split(self::SCOPE_SEPARATOR, trim($scopeHeader), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $scopes === [] || in_array('*', $scopes, true) || in_array(self::WRITE_SCOPE, $scopes, true);
    }

    /**
     * @param  array<string, mixed>  $region
     */
    private static function hostsLinodes(array $region): bool
    {
        $capabilities = $region['capabilities'] ?? null;

        return is_array($capabilities)
            && in_array('Linodes', $capabilities, true)
            && ($region['status'] ?? null) === 'ok'
            && ($region['site_type'] ?? 'core') === 'core';
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private static function size(array $type): CloudSize
    {
        $monthly = data_get($type, 'price.monthly');
        $regional = $type['region_prices'] ?? null;
        $prices = [];

        foreach (is_array($regional) ? $regional : [] as $price) {
            $region = is_array($price) ? self::text($price['id'] ?? null) : null;

            if ($region !== null && is_numeric($price['monthly'] ?? null)) {
                $prices[$region] = CloudPrice::fromNumber($price['monthly'], 'USD');
            }
        }

        return new CloudSize(
            (string) $type['id'],
            (int) $type['vcpus'],
            (int) $type['memory'],
            intdiv((int) $type['disk'], 1024),
            'x86',
            is_numeric($monthly) ? CloudPrice::fromNumber($monthly, 'USD') : null,
            $prices,
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private static function image(array $image): ?CloudImage
    {
        $id = self::text($image['id'] ?? null);

        if ($id === null || ($image['status'] ?? null) !== 'available' || self::retired($image) || preg_match(self::IMAGE_ID, $id, $match) !== 1) {
            return null;
        }

        return new CloudImage($id, $match[1], $match[2], ['x86']);
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private static function retired(array $image): bool
    {
        return ($image['deprecated'] ?? null) === true || self::ended($image['eol'] ?? null);
    }

    private static function ended(mixed $date): bool
    {
        $text = self::text($date);
        $at = $text === null ? false : date_create_immutable($text, new DateTimeZone('UTC'));

        return $at !== false && $at->getTimestamp() <= now()->getTimestamp();
    }

    private function instance(mixed $instance): CloudInstance
    {
        return new CloudInstance(
            $this->identifier(data_get($instance, 'id')),
            self::state(data_get($instance, 'status')),
            self::publicAddress(data_get($instance, 'ipv4')),
            self::text(data_get($instance, 'type')),
        );
    }

    private static function publicAddress(mixed $addresses): ?string
    {
        foreach (is_array($addresses) ? $addresses : [] as $address) {
            $public = self::publicIpv4($address);

            if ($public !== null) {
                return $public;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private static function carries(array $instance, string $tag): bool
    {
        $tags = $instance['tags'] ?? null;

        return is_array($tags) && in_array($tag, $tags, true);
    }

    private static function state(mixed $status): CloudInstanceState
    {
        return match ($status) {
            'provisioning', 'booting' => CloudInstanceState::Starting,
            'running' => CloudInstanceState::Running,
            'offline', 'stopped' => CloudInstanceState::Stopped,
            'shutting_down', 'rebooting', 'migrating', 'rebuilding', 'cloning', 'restoring', 'resizing' => CloudInstanceState::Busy,
            'deleting' => CloudInstanceState::Deleting,
            default => CloudInstanceState::Unknown,
        };
    }
}
