<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Env;

function realtimeSocketMember(): User
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user->fresh();
}

function realtimeSocketConfig(array $environment): array
{
    $repository = Env::getRepository();

    foreach ($environment as $name => $value) {
        $repository->set($name, $value);
    }

    try {
        return (require config_path('coresmith.php'))['live']['socket'];
    } finally {
        foreach (array_keys($environment) as $name) {
            $repository->clear($name);
        }
    }
}

test('pages are told where the realtime socket is when they load, so a new address needs no rebuild', function () {
    config(['coresmith.live.socket' => ['key' => 'panel-key', 'host' => 'coresmith.example.com', 'port' => 443, 'scheme' => 'https']]);

    $this->actingAs(realtimeSocketMember())
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('panel.live.socket', ['key' => 'panel-key', 'host' => 'coresmith.example.com', 'port' => 443, 'tls' => true]));
});

test('a socket served without tls is told so', function () {
    config(['coresmith.live.socket' => ['key' => 'panel-key', 'host' => 'localhost', 'port' => 8080, 'scheme' => 'http']]);

    $this->actingAs(realtimeSocketMember())
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('panel.live.socket.tls', false)->where('panel.live.socket.port', 8080));
});

test('without a key there is no socket to connect to', function () {
    config(['coresmith.live.socket' => ['key' => '', 'host' => 'coresmith.example.com', 'port' => 443, 'scheme' => 'https']]);

    $this->actingAs(realtimeSocketMember())
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('panel.live.socket', null));
});

test('the socket settings come from the environment the installer writes', function () {
    expect(realtimeSocketConfig([
        'VITE_REVERB_APP_KEY' => 'installed-key',
        'VITE_REVERB_HOST' => 'coresmith.example.com',
        'VITE_REVERB_PORT' => '443',
        'VITE_REVERB_SCHEME' => 'https',
    ]))->toBe(['key' => 'installed-key', 'host' => 'coresmith.example.com', 'port' => 443, 'scheme' => 'https']);
});
