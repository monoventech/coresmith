<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\ManageFirewallRule;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function firewallRuleOwner(array $server = []): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return [$user->fresh(), Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'ssh_port' => 22, ...$server])];
}

test("on Coresmith's own machine, denying 80 or 443 is refused, since that's how people reach Coresmith", function (int $port) {
    Queue::fake();
    [$owner, $server] = firewallRuleOwner(['runs_coresmith' => true]);

    $this->actingAs($owner)->post(route('servers.firewall.store', $server), ['action' => 'deny', 'port' => $port])
        ->assertSessionHas('error', "Coresmith runs on this machine, and people reach it through port {$port}. Denying it would cut everyone off from Coresmith.");

    Queue::assertNothingPushed();
})->with([80, 443]);

test("on Coresmith's own machine, other rules still go through", function (string $action, int $port) {
    Queue::fake();
    [$owner, $server] = firewallRuleOwner(['runs_coresmith' => true]);

    $this->actingAs($owner)->post(route('servers.firewall.store', $server), ['action' => $action, 'port' => $port])
        ->assertSessionHas('success');

    Queue::assertPushed(ManageFirewallRule::class);
})->with([
    'allowing 443' => ['allow', 443],
    'denying another port' => ['deny', 6379],
]);

test('on any other server, 80 and 443 can be denied', function () {
    Queue::fake();
    [$owner, $server] = firewallRuleOwner();

    $this->actingAs($owner)->post(route('servers.firewall.store', $server), ['action' => 'deny', 'port' => 443])->assertSessionHas('success');

    Queue::assertPushed(ManageFirewallRule::class);
});

test('denying the SSH port Coresmith uses is refused everywhere', function () {
    Queue::fake();
    [$owner, $server] = firewallRuleOwner(['ssh_port' => 2222]);

    $this->actingAs($owner)->post(route('servers.firewall.store', $server), ['action' => 'deny', 'port' => 2222])
        ->assertSessionHas('error', 'That is the SSH port Coresmith uses to reach this server. Denying it would lock you out.');

    Queue::assertNothingPushed();
});

test("on Coresmith's own machine, which Coresmith reaches over loopback, denying the SSH port is refused for the people who sign in there", function () {
    Queue::fake();
    [$owner, $server] = firewallRuleOwner(['runs_coresmith' => true]);

    $this->actingAs($owner)->post(route('servers.firewall.store', $server), ['action' => 'deny', 'port' => 22])
        ->assertSessionHas('error', "That is this machine's SSH port. Denying it would lock you out of the machine.");

    Queue::assertNothingPushed();
});
