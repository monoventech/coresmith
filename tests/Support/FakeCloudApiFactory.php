<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\CloudProviderType;
use App\Models\CloudProvider;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Cloud\Contracts\CloudApiFactory;

final class FakeCloudApiFactory implements CloudApiFactory
{
    /** @var list<array{0: CloudProviderType, 1: string}> */
    private array $made = [];

    /** @var list<string> */
    private array $accounts = [];

    public function __construct(public readonly FakeCloudApi $api = new FakeCloudApi) {}

    public function for(CloudProvider $account): CloudApi
    {
        $this->accounts[] = (string) $account->getKey();

        return $this->api;
    }

    public function make(CloudProviderType $type, #[\SensitiveParameter] string $token): CloudApi
    {
        $this->made[] = [$type, $token];

        return $this->api;
    }

    /** @return list<array{0: CloudProviderType, 1: string}> */
    public function made(): array
    {
        return $this->made;
    }

    /** @return list<string> */
    public function accounts(): array
    {
        return $this->accounts;
    }
}
