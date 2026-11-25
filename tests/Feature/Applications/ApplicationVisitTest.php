<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\CheckCertificates;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

function visitAppViewer(Server $server, TeamRole $role): User
{
    $user = User::factory()->create();
    $server->team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function visitAppNamed(Server $server, string $slug, array $attributes): Application
{
    return Application::factory()->for($server)->create(['team_id' => $server->team_id, 'name' => $slug, 'slug' => $slug, 'additional_domains' => [], ...$attributes]);
}

test("an app's page links to its address once a release is live there", function () {
    Queue::fake([CheckCertificates::class]);
    $server = Server::factory()->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    $deployer = visitAppViewer($server, TeamRole::Member);
    $live = visitAppNamed($server, 'shop', ['domain' => 'shop.example.invalid', 'current_release' => '20261124-110000']);
    $draft = visitAppNamed($server, 'draft', ['domain' => 'draft.example.invalid', 'current_release' => null]);
    $worker = visitAppNamed($server, 'worker', ['domain' => null, 'current_release' => '20261124-110000']);

    $this->actingAs($deployer)
        ->get(route('servers.applications.show', [$server, $live]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('application.visit_href', 'https://shop.example.invalid'));

    foreach ([$draft, $worker] as $application) {
        $this->actingAs($deployer)
            ->get(route('servers.applications.show', [$server, $application]))
            ->assertInertia(fn(AssertableInertia $page) => $page->where('application.visit_href', null));
    }
});
