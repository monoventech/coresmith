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
use App\Services\SSH\HostKeyFingerprint;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use LogicException;

final class DigitalOceanApi extends HttpCloudApi implements CloudApi
{
    private const PAGE_SIZE = 200;

    private const IMAGE_SLUG = '/^(ubuntu|debian)-(\d+)(?:-(\d+))?-x64$/D';

    public function type(): CloudProviderType
    {
        return CloudProviderType::DigitalOcean;
    }

    public function verify(): CloudAccount
    {
        $account = $this->getJson('/account')->json('account');
        $status = is_array($account) ? self::text($account['status'] ?? null) : null;

        if ($status === null) {
            throw $this->unusable();
        }

        if ($status !== 'active') {
            throw new CloudProviderException(CloudFailure::Refused, $this->type(), $this->detail(self::text($account['status_message'] ?? null)));
        }

        return new CloudAccount(self::text(data_get($account, 'team.name')) ?? self::text($account['email'] ?? null));
    }

    public function regions(): Collection
    {
        return $this->pages('/regions', [], 'regions')
            ->filter(fn(mixed $region): bool => is_array($region)
                && self::text($region['slug'] ?? null) !== null
                && ($region['available'] ?? null) !== false)
            ->map(function (array $region): CloudRegion {
                $slug = (string) $region['slug'];
                $name = self::text($region['name'] ?? null);

                return new CloudRegion($slug, $name === null ? $slug : "{$name} ({$slug})");
            })
            ->values();
    }

    public function sizes(): Collection
    {
        return $this->pages('/sizes', [], 'sizes')
            ->filter(fn(mixed $size): bool => is_array($size)
                && self::text($size['slug'] ?? null) !== null
                && self::hasNumbers($size, 'vcpus', 'memory', 'disk')
                && self::offered($size))
            ->map(fn(array $size): CloudSize => self::size($size))
            ->values();
    }

    public function images(): Collection
    {
        return $this->pages('/images', ['type' => 'distribution'], 'images')
            ->map(fn(mixed $image): ?CloudImage => is_array($image) ? self::image($image) : null)
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
        $held = $fingerprint === null ? null : $this->getJsonOrNull("/account/keys/{$fingerprint}");

        if ($held !== null) {
            return $this->identifier($held->json('ssh_key.id'));
        }

        $response = $this->postJson('/account/keys', ['name' => $name, 'public_key' => $publicKey]);

        return $this->identifier($response->json('ssh_key.id'));
    }

    public function deleteKey(string $keyId): void
    {
        $this->deleteOrGone("/account/keys/{$this->segment($keyId)}");
    }

    public function create(CloudServerSpec $spec): CloudInstance
    {
        if ($spec->keyId === null) {
            throw new LogicException('DigitalOcean needs the id of an uploaded key to create a droplet.');
        }

        $response = $this->postJson('/droplets', [
            'name' => $spec->name,
            'region' => $spec->region,
            'size' => $spec->size,
            'image' => $spec->image,
            'ssh_keys' => [(int) $spec->keyId],
            'ipv6' => true,
            'monitoring' => false,
            'backups' => false,
            'tags' => ['coresmith', $spec->tag],
        ]);

        return $this->instance($response->json('droplet'));
    }

    public function find(string $instanceId): ?CloudInstance
    {
        $response = $this->getJsonOrNull("/droplets/{$this->segment($instanceId)}");

        return $response === null ? null : $this->instance($response->json('droplet'));
    }

    public function findTagged(string $tag): ?CloudInstance
    {
        $droplet = $this->firstMatch(
            $this->listed($this->getJson('/droplets', ['tag_name' => $tag]), 'droplets'),
            fn(array $droplet): bool => self::carries($droplet, $tag),
        );

        return $droplet === null ? null : $this->instance($droplet);
    }

    public function reboot(string $instanceId): void
    {
        $this->act($instanceId, ['type' => 'reboot']);
    }

    public function resizeNeedsPowerOff(): bool
    {
        return true;
    }

    public function powerOff(string $instanceId): void
    {
        $this->act($instanceId, ['type' => 'shutdown']);
    }

    public function powerOn(string $instanceId): void
    {
        $this->act($instanceId, ['type' => 'power_on']);
    }

    public function resize(string $instanceId, string $size): void
    {
        $this->act($instanceId, ['type' => 'resize', 'size' => $size, 'disk' => false]);
    }

    public function destroy(string $instanceId): void
    {
        $this->deleteOrGone("/droplets/{$this->segment($instanceId)}");
    }

    protected function message(Response $response): ?string
    {
        return self::text($response->json('message'));
    }

    protected function classify(Response $response): ?CloudFailure
    {
        return $response->status() === 422 && str_contains(strtolower((string) $this->message($response)), 'pending event')
            ? CloudFailure::Busy
            : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, mixed>
     */
    private function pages(string $path, array $query, string $key): Collection
    {
        $query = [...$query, 'per_page' => self::PAGE_SIZE];

        return $this->paginate($path, $query, $key, function (Response $response) use ($query): ?array {
            $page = self::nextPage($response->json('links.pages.next'));

            return $page === null ? null : [...$query, 'page' => $page];
        });
    }

    private static function nextPage(mixed $link): ?int
    {
        if (!is_string($link)) {
            return null;
        }

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $page = $query['page'] ?? null;

        return is_string($page) && ctype_digit($page) && (int) $page > 0 ? (int) $page : null;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function act(string $instanceId, array $action): void
    {
        $this->postJson("/droplets/{$this->segment($instanceId)}/actions", $action);
    }

    /**
     * @param  array<string, mixed>  $size
     */
    private static function offered(array $size): bool
    {
        return ($size['available'] ?? null) !== false
            && !str_starts_with((string) $size['slug'], 'gpu-')
            && empty($size['gpu_info']);
    }

    /**
     * @param  array<string, mixed>  $size
     */
    private static function size(array $size): CloudSize
    {
        $monthly = $size['price_monthly'] ?? null;

        return new CloudSize(
            (string) $size['slug'],
            (int) $size['vcpus'],
            (int) $size['memory'],
            (int) $size['disk'],
            'x86',
            is_numeric($monthly) ? CloudPrice::fromNumber($monthly, 'USD') : null,
            [],
            self::names($size['regions'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private static function image(array $image): ?CloudImage
    {
        $slug = self::text($image['slug'] ?? null);

        if ($slug === null || ($image['status'] ?? null) !== 'available' || preg_match(self::IMAGE_SLUG, $slug, $match) !== 1) {
            return null;
        }

        return new CloudImage($slug, $match[1], isset($match[3]) ? "{$match[2]}.{$match[3]}" : $match[2], ['x86']);
    }

    private function instance(mixed $droplet): CloudInstance
    {
        return new CloudInstance(
            $this->identifier(data_get($droplet, 'id')),
            self::state(data_get($droplet, 'status')),
            self::publicAddress(data_get($droplet, 'networks.v4')),
            self::text(data_get($droplet, 'size_slug')),
        );
    }

    private static function publicAddress(mixed $networks): ?string
    {
        foreach (is_array($networks) ? $networks : [] as $network) {
            $address = is_array($network) && ($network['type'] ?? null) === 'public'
                ? self::publicIpv4($network['ip_address'] ?? null)
                : null;

            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, fn(mixed $value): bool => self::text($value) !== null))
            : [];
    }

    /**
     * @param  array<string, mixed>  $droplet
     */
    private static function carries(array $droplet, string $tag): bool
    {
        $tags = $droplet['tags'] ?? null;

        return is_array($tags) && in_array($tag, $tags, true);
    }

    private static function state(mixed $status): CloudInstanceState
    {
        return match ($status) {
            'new' => CloudInstanceState::Starting,
            'active' => CloudInstanceState::Running,
            'off' => CloudInstanceState::Stopped,
            'archive' => CloudInstanceState::Deleting,
            default => CloudInstanceState::Unknown,
        };
    }
}
