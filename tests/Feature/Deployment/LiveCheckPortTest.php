<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\LiveAnswer;
use App\Services\Deployment\LiveCheck;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function liveCheckPortAsk(FakeSSHTransport $transport, array $attributes = []): ?LiveAnswer
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $application = Application::factory()->for($server)->create(['slug' => 'notes', 'type' => ApplicationType::Js, 'base_port' => 8090, 'domain' => 'notes.example.com', ...$attributes]);

    return app(LiveCheck::class)->ask((new SSHConnection($server, $transport))->connect(), $application);
}

test('a 502 from an app that listens on a port of its own says which, and to use PORT', function () {
    $answer = liveCheckPortAsk((new FakeSSHTransport)->respondTo('curl -sk', '502')->respondTo('ss -ltnpH', "0.0.0.0:3000\n[::]:3000\n"));

    expect($answer?->status)->toBe(502)
        ->and($answer?->detail)->toBe('The app listens on port 3000, not the port Coresmith gives it, 8090. Have it listen on the PORT environment variable.');
});

test('a 502 from an app on the right port, or one whose listeners cannot be read, adds nothing', function (string $listeners) {
    $answer = liveCheckPortAsk((new FakeSSHTransport)->respondTo('curl -sk', '502')->respondTo('ss -ltnpH', $listeners));

    expect($answer?->detail)->toBeNull();
})->with([
    'on its port' => ["127.0.0.1:8090\n"],
    'nothing read' => [''],
]);
