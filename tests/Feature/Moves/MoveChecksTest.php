<?php

declare(strict_types=1);

use App\Services\Moves\MoveChecks;
use App\Services\Network\LocalAddresses;
use Tests\Support\CloudBilling;
use Tests\Support\MovePayloads;

/**
 * @return array<string, mixed>
 */
function moveChecksPayload(): array
{
    return MovePayloads::selfHosted();
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
    'a runtime user Coresmith does not use' => [['meta' => ['cpu_cores' => 2, 'runtime_user' => 'root']]],
]);

test('the machine Coresmith ran on moves with the runtime user its apps already use', function () {
    $payload = moveChecksWith('servers', ['meta' => ['cpu_cores' => 2, 'runtime_user' => 'coresmith-runtime']]);

    expect(app(MoveChecks::class)->problems($payload))->toBe([]);

    CloudBilling::enable();

    expect(app(MoveChecks::class)->problems($payload))->toBe([]);
});

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

test('records the importer skips because a newer Coresmith made them are not judged', function () {
    CloudBilling::enable();
    $payload = moveChecksPayload();
    $payload['package_credentials'][] = ['project' => null, 'source' => 'cargo', 'target' => 'crates.acme.test', 'scope' => null, 'username' => null, 'secret' => 'not judged'];
    $payload['source_providers'][] = ['ref' => 'fj', 'type' => 'forgejo', 'name' => 'Forgejo', 'custom_host' => 'not a host'];

    expect(app(MoveChecks::class)->problems($payload))->toBe([]);
});

test('only the first ten problems are listed', function () {
    CloudBilling::enable();
    $payload = moveChecksPayload();
    $payload['servers'] = array_map(fn(int $n): array => [...$payload['servers'][0], 'ref' => "s{$n}", 'name' => "web-{$n}", 'ip_address' => "10.0.0.{$n}"], range(1, 12));

    expect(app(MoveChecks::class)->problems($payload))->toHaveCount(10);
});
