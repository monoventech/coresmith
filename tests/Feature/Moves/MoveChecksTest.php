<?php

declare(strict_types=1);

use App\Services\Moves\MoveChecks;
use App\Services\Network\LocalAddresses;
use App\Services\SSH\KeyPairGenerator;
use Tests\Support\CloudBilling;

/**
 * @return array<string, mixed>
 */
function moveChecksPayload(): array
{
    $key = (new KeyPairGenerator)->generate('move-checks');

    return [
        'id' => 'b2b0c0de-0000-4000-8000-000000000009',
        'made_at' => '2026-11-23T09:00:00+00:00',
        'from' => 'https://coresmith.example.com',
        'edition' => 'self-hosted',
        'team' => ['name' => 'Arrivals', 'slug' => 'arrivals'],
        'members' => [['email' => 'lin@acme.test', 'role' => 'member', 'sees_everything' => true]],
        'cloud_providers' => [['ref' => 'cp1', 'type' => 'hetzner', 'name' => 'Hetzner', 'account_label' => 'acme', 'api_token' => 'token', 'verified_at' => null]],
        'servers' => [[
            'ref' => 's1', 'cloud_provider' => 'cp1', 'tags' => ['production'], 'name' => 'web-01', 'ip_address' => '203.0.113.60', 'ssh_port' => 22, 'ssh_user' => 'root',
            'ssh_private_key' => $key->privateKey, 'ssh_public_key' => $key->publicKey, 'host_key_fingerprint' => 'SHA256:' . str_repeat('A', 43),
            'os_name' => 'ubuntu', 'os_version' => '24.04', 'provisioning_phase' => 8, 'installed_runtimes' => [], 'meta' => ['cpu_cores' => 2], 'audit_logging_enabled' => false,
            'region' => 'fsn1', 'booted_at' => null, 'provider_server_id' => null, 'provider_size' => null, 'provider_image' => null, 'provider_monthly_cost' => null, 'provider_currency' => null,
        ]],
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

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function moveChecksWith(string $list, array $changes): array
{
    $payload = moveChecksPayload();
    $payload[$list][0] = [...$payload[$list][0], ...$changes];

    return $payload;
}

beforeEach(function () {
    app()->instance(LocalAddresses::class, new class implements LocalAddresses
    {
        public function all(): array
        {
            return ['127.0.0.1', '::1', '203.0.113.10'];
        }
    });
});

test('a well-formed move file has no problems on either edition', function () {
    expect(app(MoveChecks::class)->problems(moveChecksPayload()))->toBe([]);

    CloudBilling::enable();

    expect(app(MoveChecks::class)->problems(moveChecksPayload()))->toBe([]);
});

test('Coresmith Cloud refuses a server it could only reach inside its own network', function (string $address) {
    CloudBilling::enable();

    expect(app(MoveChecks::class)->problems(moveChecksWith('servers', ['ip_address' => $address])))
        ->toBe(["web-01 is at {$address}, which Coresmith Cloud can't reach. Give it a public address before you move the team."]);
})->with(['a private network' => '10.0.0.5', 'loopback' => '127.0.0.1', 'Tailscale and carrier NAT' => '100.100.100.100', "Coresmith Cloud's own machine" => '203.0.113.10']);

test('a self-hosted Coresmith takes a server on a private network', function () {
    expect(app(MoveChecks::class)->problems(moveChecksWith('servers', ['ip_address' => '10.0.0.5'])))->toBe([]);
});

test('a server whose connection details are broken is refused', function (array $changes) {
    CloudBilling::enable();

    expect(app(MoveChecks::class)->problems(moveChecksWith('servers', $changes)))
        ->toBe(["web-01's connection details in the file aren't valid. Download the move file again."]);
})->with([
    'port 0' => [['ssh_port' => 0]],
    'port 70000' => [['ssh_port' => 70000]],
    'a user with a space' => [['ssh_user' => 'ro ot']],
    'a fingerprint that is not SHA256' => [['host_key_fingerprint' => 'MD5:xx']],
    'a private key that does not parse' => [['ssh_private_key' => 'not a key']],
    'an address that is not one' => [['ip_address' => 'web-01.example.com']],
]);

test('an app whose settings are broken is refused', function (array $changes) {
    CloudBilling::enable();

    expect(app(MoveChecks::class)->problems(moveChecksWith('applications', $changes)))
        ->toBe(["storefront's settings in the file aren't valid. Download the move file again."]);
})->with([
    'a slug that climbs out' => [['slug' => '../x']],
    'a system user with a command in it' => [['system_user' => 'root;rm']],
    'a domain that is not one' => [['domain' => 'not a domain']],
    'a server the file does not have' => [['server' => 's9']],
    'a type Coresmith does not know' => [['type' => 'cobol']],
]);

test('a database whose settings are broken is refused', function () {
    CloudBilling::enable();

    expect(app(MoveChecks::class)->problems(moveChecksWith('databases', ['name' => 'drop table;'])))
        ->toBe(["The drop table; database's settings in the file aren't valid. Download the move file again."]);
});

test("Coresmith Cloud checks the team's S3 settings like its own form", function () {
    CloudBilling::enable();
    $payload = moveChecksPayload();
    $payload['backup_destination']['endpoint'] = 'http://bucket.example.com';

    expect(app(MoveChecks::class)->problems($payload))
        ->toBe(["The team's S3 backup settings in the file aren't valid. Use an https:// address, like https://s3.eu-central-1.amazonaws.com."]);
});

test('Coresmith Cloud invites at most 50 teammates from one move', function () {
    CloudBilling::enable();
    $payload = moveChecksPayload();
    $payload['members'] = array_map(fn(int $n): array => ['email' => "mate{$n}@acme.test", 'role' => 'member'], range(1, 51));

    expect(app(MoveChecks::class)->problems($payload))
        ->toBe(['This team has more than 50 teammates to invite. Move it with fewer, then invite the rest here.']);
});

test('a self-hosted Coresmith takes a team with more than 50 teammates', function () {
    $payload = moveChecksPayload();
    $payload['members'] = array_map(fn(int $n): array => ['email' => "mate{$n}@acme.test", 'role' => 'member'], range(1, 51));

    expect(app(MoveChecks::class)->problems($payload))->toBe([]);
});

test('only the first ten problems are listed', function () {
    CloudBilling::enable();
    $payload = moveChecksPayload();
    $payload['servers'] = array_map(fn(int $n): array => [...$payload['servers'][0], 'ref' => "s{$n}", 'name' => "web-{$n}", 'ip_address' => "10.0.0.{$n}"], range(1, 12));

    expect(app(MoveChecks::class)->problems($payload))->toHaveCount(10);
});
