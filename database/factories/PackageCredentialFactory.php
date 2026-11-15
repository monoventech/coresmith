<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PackageSource;
use App\Models\PackageCredential;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageCredential>
 */
class PackageCredentialFactory extends Factory
{
    protected $model = PackageCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'project_id' => null,
            'source' => PackageSource::Composer,
            'target' => 'nova.laravel.com',
            'scope' => null,
            'username' => 'ops@example.com',
            'secret' => 'nova-license-key-0000',
        ];
    }

    public function npm(string $registry = 'https://npm.pkg.github.com/', ?string $scope = '@acme', string $token = 'ghp_npmtoken0000'): static
    {
        return $this->state(['source' => PackageSource::Npm, 'target' => $registry, 'scope' => $scope, 'username' => null, 'secret' => $token]);
    }

    public function python(string $host = 'pypi.acme.dev', string $username = 'deploy', string $password = 'py-secret-0000'): static
    {
        return $this->state(['source' => PackageSource::Python, 'target' => $host, 'scope' => null, 'username' => $username, 'secret' => $password]);
    }

    public function maven(string $id = 'acme-releases', string $username = 'deploy', string $password = 'mvn-secret-0000'): static
    {
        return $this->state(['source' => PackageSource::Maven, 'target' => $id, 'scope' => null, 'username' => $username, 'secret' => $password]);
    }

    public function ruby(string $host = 'gems.acme.dev', string $username = 'deploy', string $password = 'gem-secret-0000'): static
    {
        return $this->state(['source' => PackageSource::Ruby, 'target' => $host, 'scope' => null, 'username' => $username, 'secret' => $password]);
    }

    public function git(string $target = 'github.com/acme', ?string $username = null, string $token = 'ghp_gittoken0000'): static
    {
        return $this->state(['source' => PackageSource::Git, 'target' => $target, 'scope' => null, 'username' => $username, 'secret' => $token]);
    }
}
