<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function cloudRefusalServer(): Server
{
    return Server::factory()->fromProvider(CloudProvider::factory()->create())->create(['name' => 'outpost-02', 'status' => ServerStatus::Active]);
}

test('a running provider task says what Coresmith is busy with', function (string $type, string $step, string $message) {
    $server = cloudRefusalServer();
    CloudTask::start($server, $type, $step);

    expect(CloudTask::refusal($server->fresh(), 'Deploy'))->toBe($message);
})->with([
    'a resize' => [CloudTask::RESIZE, 'change', "Coresmith is resizing outpost-02 at Hetzner Cloud. Deploy once it's back."],
    'a destroy' => [CloudTask::DESTROY, 'delete', 'Coresmith is destroying outpost-02 at Hetzner Cloud.'],
    'a creation' => [CloudTask::CREATE, 'boot', 'Coresmith is still creating outpost-02 at Hetzner Cloud.'],
]);

test('a provider task that stopped, or none at all, refuses nothing', function (?string $ending) {
    $server = cloudRefusalServer();

    if ($ending !== null) {
        CloudTask::start($server, CloudTask::RESIZE, 'change');
        $ending === CloudTask::FAILED ? CloudTask::fail($server, 'Hetzner Cloud refused the request.') : CloudTask::finish($server);
    }

    expect(CloudTask::refusal($server->fresh(), 'Deploy'))->toBeNull();
})->with(['no task' => [null], 'a failed task' => [CloudTask::FAILED], 'a finished task' => [CloudTask::DONE]]);
