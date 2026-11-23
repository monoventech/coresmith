<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\SSH\KeyPairGenerator;

final class MovePayloads
{
    /**
     * @param  list<string>  $members
     * @return array<string, mixed>
     */
    public static function selfHosted(int $servers = 1, array $members = ['lin@acme.test']): array
    {
        $records = [];

        foreach (range(1, $servers) as $n) {
            $key = (new KeyPairGenerator)->generate("move-{$n}");
            $records[] = [
                'ref' => "s{$n}", 'cloud_provider' => 'cp1', 'tags' => ['production'], 'name' => "web-0{$n}", 'ip_address' => '203.0.113.' . (60 + $n), 'ssh_port' => 22, 'ssh_user' => 'root',
                'ssh_private_key' => $key->privateKey, 'ssh_public_key' => $key->publicKey, 'host_key_fingerprint' => 'SHA256:' . str_repeat(chr(64 + $n), 43),
                'os_name' => 'ubuntu', 'os_version' => '24.04', 'provisioning_phase' => 8, 'installed_runtimes' => [], 'meta' => ['cpu_cores' => 2], 'audit_logging_enabled' => false,
                'region' => 'fsn1', 'booted_at' => null, 'provider_server_id' => null, 'provider_size' => null, 'provider_image' => null, 'provider_monthly_cost' => null, 'provider_currency' => null,
            ];
        }

        return [
            'id' => 'b2b0c0de-0000-4000-8000-000000000009',
            'made_at' => '2026-11-23T09:00:00+00:00',
            'from' => 'https://coresmith.example.com',
            'edition' => 'self-hosted',
            'team' => ['name' => 'Arrivals', 'slug' => 'arrivals'],
            'members' => array_map(fn(string $email): array => ['email' => $email, 'role' => 'member', 'sees_everything' => true], $members),
            'cloud_providers' => [['ref' => 'cp1', 'type' => 'hetzner', 'name' => 'Hetzner', 'account_label' => 'acme', 'api_token' => 'token', 'verified_at' => null]],
            'servers' => $records,
            'source_providers' => [['ref' => 'gh', 'type' => 'github_app', 'name' => 'GitHub', 'custom_host' => null]],
            'projects' => [['ref' => 'p1', 'name' => 'Storefront']],
            'applications' => [[
                'ref' => 'a1', 'server' => 's1', 'source_provider' => 'gh', 'project' => 'p1', 'tags' => [], 'name' => 'storefront', 'slug' => 'storefront', 'type' => 'php',
                'system_user' => 'coresmith-app-storefront', 'base_port' => 8100, 'status' => 'active', 'domain' => 'shop.example.com', 'additional_domains' => ['www.shop.example.com'],
            ]],
            'webhook_endpoints' => [['application' => 'a1', 'secret' => 'whsec_1', 'provider' => 'github', 'active' => true]],
            'databases' => [['server' => 's1', 'project' => 'p1', 'type' => 'mysql', 'name' => 'storefront', 'db_user' => 'storefront', 'db_password' => 'secret', 'host' => '127.0.0.1', 'port' => 3306, 'status' => 'active', 'privilege_level' => 'full', 'extensions' => [], 'is_external' => false]],
            'backup_destination' => ['endpoint' => 'https://s3.eu-central-1.amazonaws.com', 'region' => 'eu-central-1', 'bucket' => 'acme-backups', 'prefix' => null, 'access_key_id' => 'AKIA', 'secret_access_key' => 'secret', 'path_style' => false, 'last_run_at' => null],
            'package_credentials' => [['project' => null, 'source' => 'composer', 'target' => 'nova.laravel.com', 'scope' => null, 'username' => 'ada@acme.test', 'secret' => 'token']],
        ];
    }
}
