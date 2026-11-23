<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Exceptions\OffsiteListFailed;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\Team;
use App\Services\Database\OffsiteBackups;
use App\Services\Database\S3DestinationInput;
use App\Services\Network\AddressResolver;
use App\Services\Network\LocalAddresses;
use App\Services\Network\OutboundAddress;
use App\Services\Sso\UrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\Support\CloudBilling;

/**
 * @param  array<string, list<string>>  $answers
 */
function cloudOutboundNetwork(array $answers): void
{
    app()->instance(AddressResolver::class, new class($answers) implements AddressResolver
    {
        public function __construct(private readonly array $answers) {}

        public function addresses(string $host): array
        {
            return $this->answers[$host] ?? [];
        }
    });
    app()->instance(LocalAddresses::class, new class implements LocalAddresses
    {
        public function all(): array
        {
            return ['127.0.0.1', '::1', '203.0.113.10'];
        }
    });
}

function cloudOutboundEndpointError(string $endpoint): ?string
{
    $validator = Validator::make(
        ['endpoint' => $endpoint, 'region' => 'eu-central-1', 'bucket' => 'lab-backups', 'access_key_id' => 'AKIA', 'secret_access_key' => 'secret'],
        S3DestinationInput::rules(null),
        S3DestinationInput::messages(),
    );

    return $validator->fails() ? $validator->errors()->first('endpoint') : null;
}

beforeEach(fn() => cloudOutboundNetwork([
    's3.example.com' => ['203.0.113.5'],
    'minio.internal.example' => ['10.0.0.9'],
    'sneaky.example' => ['203.0.113.6', '169.254.169.254'],
]));

test('Coresmith Cloud refuses an S3 endpoint that points inside its own network', function (string $endpoint, string $error) {
    CloudBilling::enable();

    expect(cloudOutboundEndpointError($endpoint))->toBe($error);
})->with([
    'a private address' => ['https://10.0.0.5', "That endpoint points at a private address, so Coresmith Cloud can't reach it. Use your storage provider's public endpoint."],
    'a name that resolves inside' => ['https://minio.internal.example', "That endpoint points at a private address, so Coresmith Cloud can't reach it. Use your storage provider's public endpoint."],
    'one answer of several inside' => ['https://sneaky.example:9000', "That endpoint points at a private address, so Coresmith Cloud can't reach it. Use your storage provider's public endpoint."],
    "Coresmith Cloud's own machine" => ['https://203.0.113.10', "That endpoint points at a private address, so Coresmith Cloud can't reach it. Use your storage provider's public endpoint."],
    'a name with no address' => ['https://nowhere.example', "Coresmith Cloud couldn't find an address for nowhere.example. Check the endpoint."],
]);

test('Coresmith Cloud takes a public S3 endpoint', function () {
    CloudBilling::enable();

    expect(cloudOutboundEndpointError('https://s3.example.com'))->toBeNull();
});

test('self-hosted Coresmith takes an S3 endpoint on its own network', function () {
    expect(cloudOutboundEndpointError('https://10.0.0.5'))->toBeNull()
        ->and(cloudOutboundEndpointError('https://minio.internal.example'))->toBeNull();
});

test('requests Coresmith Cloud makes are pinned to the addresses it checked', function () {
    CloudBilling::enable();
    $outbound = app(OutboundAddress::class);

    expect($outbound->options('https://s3.example.com/lab-backups?list-type=2'))->toBe(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ['s3.example.com:443:203.0.113.5']]])
        ->and($outbound->options('https://203.0.113.20/lab-backups'))->toBe(['allow_redirects' => false]);

    config(['coresmith.edition' => 'self-hosted']);

    expect($outbound->options('https://minio.internal.example/lab-backups'))->toBe([]);
});

test('listing backups refuses an endpoint whose name moved inside since it was saved, without sending anything', function () {
    CloudBilling::enable();
    Http::preventStrayRequests();
    $team = Team::factory()->subscribed(Plan::Business)->create();
    $destination = BackupDestination::factory()->create(['team_id' => $team->id, 'endpoint' => 'https://minio.internal.example', 'path_style' => true]);
    $database = ManagedDatabase::factory()->for($team)->create();

    expect(fn() => app(OffsiteBackups::class)->listed($destination, $database))
        ->toThrow(OffsiteListFailed::class, "Its endpoint points at a private address, so Coresmith Cloud can't reach it.");

    Http::assertNothingSent();
});

test('single sign-on on Coresmith Cloud refuses a provider on a private network', function (string $issuer) {
    CloudBilling::enable();

    expect(app(UrlGuard::class)->issuerRefusal($issuer))->toContain('because it points at a private address');
})->with(['10.x' => 'https://10.0.0.5/realms/acme', '192.168.x' => 'https://192.168.0.248/realms/acme', 'Tailscale' => 'https://100.100.100.100/realms/acme', 'a name inside' => 'https://minio.internal.example/realms/acme']);

test('single sign-on on self-hosted Coresmith still reaches a provider on the office network', function () {
    expect(app(UrlGuard::class)->issuerRefusal('https://10.0.0.5/realms/acme'))->toBeNull();
});

test('listing backups never follows the endpoint somewhere else', function () {
    CloudBilling::enable();
    Http::preventStrayRequests();
    Http::fake([
        's3.example.com/*' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Http::response('<ListBucketResult></ListBucketResult>', 200),
    ]);
    $team = Team::factory()->subscribed(Plan::Business)->create();
    $destination = BackupDestination::factory()->create(['team_id' => $team->id, 'endpoint' => 'https://s3.example.com', 'path_style' => true]);
    $database = ManagedDatabase::factory()->for($team)->create();

    expect(fn() => app(OffsiteBackups::class)->listed($destination, $database))->toThrow(OffsiteListFailed::class);

    Http::assertNotSent(fn($request): bool => str_contains($request->url(), '169.254.169.254'));
});
