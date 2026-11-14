<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use Illuminate\Support\Facades\Process;

function goFixture(string $fixture): RepositoryFacts
{
    $directory = base_path('tests/Fixtures/repositories/' . $fixture);
    $output = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => $directory])->input(app(RepositoryProbe::class)->script())->run('bash -s')->output();

    return app(RepositoryProbe::class)->parse($output, str_contains($fixture, '/') ? substr($fixture, strpos($fixture, '/') + 1) : '');
}

test('the probe reads a real Go module, and detection names its framework, what it runs and how it listens', function (string $fixture, string $framework, ?string $main, array $tooling) {
    $facts = goFixture($fixture);
    $proposal = ApplicationType::Go->language()->detect($facts);
    $application = new Application(['type' => ApplicationType::Go, 'framework' => $framework, 'main_package' => $main]);

    expect($proposal['type'])->toBe('go')
        ->and($proposal['framework'])->toBe($framework)
        ->and($proposal['unsettled'])->toBe([])
        ->and($proposal['fields'])->toBe(['main_package' => $main])
        ->and(ApplicationType::Go->language()->checks($application, $framework, $facts, 'x86_64'))->toBe([])
        ->and(ApplicationType::Go->language()->tooling($application, $facts))->toMatchArray($tooling);
})->with([
    'Chi with a cmd layout and a worker' => ['go-chi-cmd', 'chi', 'cmd/api', ['mains' => ['cmd/api' => 'api', 'cmd/worker' => 'worker'], 'web' => 'cmd/api', 'listen' => [], 'flag' => null, 'grpc' => false]],
    'Gin at the module root' => ['go-gin-root', 'gin', '.', ['mains' => ['.' => 'notes'], 'web' => '.', 'flag' => null]],
    'net/http with an address flag' => ['go-net-flag', 'net-http', '.', ['mains' => ['.' => 'status'], 'flag' => '-addr=127.0.0.1:${PORT}']],
    'a gRPC service' => ['go-grpc-only', 'grpc', '.', ['mains' => ['.' => 'ledger'], 'grpc' => true]],
    'a module in a go.work workspace' => ['go-workspace/apps/api', 'net-http', '.', ['mains' => ['.' => 'api'], 'web' => '.']],
]);
