<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Services\Cloud\CloudAccount;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Contracts\CloudApi;
use Illuminate\Support\Collection;

final class FakeCloudApi implements CloudApi
{
    private CloudAccount $account;

    /** @var list<CloudRegion> */
    private array $regions = [];

    /** @var list<CloudSize> */
    private array $sizes = [];

    /** @var list<CloudImage> */
    private array $images = [];

    private ?CloudInstance $created = null;

    private string $keyId = 'key-1';

    /** @var list<?CloudInstance> */
    private array $found = [];

    private int $foundAt = 0;

    /** @var list<?CloudInstance> */
    private array $tagged = [];

    private int $taggedAt = 0;

    /** @var array<string, list<CloudProviderException>> */
    private array $failures = [];

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $calls = [];

    public function __construct(
        private readonly CloudProviderType $type = CloudProviderType::Hetzner,
        private readonly bool $uploads = true,
        private readonly bool $powerCycle = true,
    ) {
        $this->account = new CloudAccount(null);
    }

    public function account(CloudAccount $account): self
    {
        $this->account = $account;

        return $this;
    }

    /**
     * @param  list<CloudRegion>  $regions
     * @param  list<CloudSize>  $sizes
     * @param  list<CloudImage>  $images
     */
    public function catalog(array $regions, array $sizes, array $images): self
    {
        [$this->regions, $this->sizes, $this->images] = [$regions, $sizes, $images];

        return $this;
    }

    public function creates(CloudInstance $instance): self
    {
        $this->created = $instance;

        return $this;
    }

    public function finds(?CloudInstance ...$sequence): self
    {
        [$this->found, $this->foundAt] = [array_values($sequence), 0];

        return $this;
    }

    public function tagged(?CloudInstance ...$sequence): self
    {
        [$this->tagged, $this->taggedAt] = [array_values($sequence), 0];

        return $this;
    }

    public function failing(string $method, CloudProviderException $e, int $times = 1): self
    {
        for ($queued = 0; $queued < $times; $queued++) {
            $this->failures[$method][] = $e;
        }

        return $this;
    }

    public function keyId(string $keyId): self
    {
        $this->keyId = $keyId;

        return $this;
    }

    /**
     * @return list<array{0: string, 1: list<mixed>}>
     */
    public function calls(?string $method = null): array
    {
        if ($method === null) {
            return $this->calls;
        }

        return array_values(array_filter($this->calls, fn(array $call): bool => $call[0] === $method));
    }

    public function type(): CloudProviderType
    {
        $this->enter(__FUNCTION__);

        return $this->type;
    }

    public function verify(): CloudAccount
    {
        $this->enter(__FUNCTION__);

        return $this->account;
    }

    public function regions(): Collection
    {
        $this->enter(__FUNCTION__);

        return new Collection($this->regions);
    }

    public function sizes(): Collection
    {
        $this->enter(__FUNCTION__);

        return new Collection($this->sizes);
    }

    public function images(): Collection
    {
        $this->enter(__FUNCTION__);

        return new Collection($this->images);
    }

    public function uploadsKeys(): bool
    {
        $this->enter(__FUNCTION__);

        return $this->uploads;
    }

    public function uploadKey(string $name, string $publicKey): string
    {
        $this->enter(__FUNCTION__, [$name, $publicKey]);

        return $this->keyId;
    }

    public function deleteKey(string $keyId): void
    {
        $this->enter(__FUNCTION__, [$keyId]);
    }

    public function create(CloudServerSpec $spec): CloudInstance
    {
        $this->enter(__FUNCTION__, [$spec]);

        return $this->created ?? new CloudInstance('4711', CloudInstanceState::Starting, null, 'cx22');
    }

    public function find(string $instanceId): ?CloudInstance
    {
        $this->enter(__FUNCTION__, [$instanceId]);

        return self::next($this->found, $this->foundAt);
    }

    public function findTagged(string $tag): ?CloudInstance
    {
        $this->enter(__FUNCTION__, [$tag]);

        return self::next($this->tagged, $this->taggedAt);
    }

    public function reboot(string $instanceId): void
    {
        $this->enter(__FUNCTION__, [$instanceId]);
    }

    public function resizeNeedsPowerOff(): bool
    {
        $this->enter(__FUNCTION__);

        return $this->powerCycle;
    }

    public function powerOff(string $instanceId): void
    {
        $this->enter(__FUNCTION__, [$instanceId]);
    }

    public function powerOn(string $instanceId): void
    {
        $this->enter(__FUNCTION__, [$instanceId]);
    }

    public function resize(string $instanceId, string $size): void
    {
        $this->enter(__FUNCTION__, [$instanceId, $size]);
    }

    public function destroy(string $instanceId): void
    {
        $this->enter(__FUNCTION__, [$instanceId]);
    }

    /**
     * @param  list<mixed>  $arguments
     */
    private function enter(string $method, array $arguments = []): void
    {
        $this->calls[] = [$method, $arguments];

        if (($this->failures[$method] ?? []) !== []) {
            throw array_shift($this->failures[$method]);
        }
    }

    /**
     * @param  list<?CloudInstance>  $sequence
     */
    private static function next(array $sequence, int &$position): ?CloudInstance
    {
        if ($sequence === []) {
            return null;
        }

        return $sequence[min($position++, count($sequence) - 1)];
    }
}
