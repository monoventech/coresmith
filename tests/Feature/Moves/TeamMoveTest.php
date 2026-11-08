<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Exceptions\PlanRefusal;
use App\Models\Application;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanGate;
use App\Services\Deployment\DeployGuard;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\MoveFile;
use App\Services\Moves\TeamMove;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

function teamMoveTeam(?Team $team = null): Team
{
    $team ??= Team::factory()->onTrial()->owned()->create(['name' => 'Acme']);
    $server = Server::factory()->active()->create(['team_id' => $team->id, 'name' => 'web-01', 'host_key_fingerprint' => 'SHA256:web']);
    Application::factory()->create(['team_id' => $team->id, 'server_id' => $server->id, 'name' => 'site']);

    return $team->fresh();
}

function teamMoveOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function teamMoveCodeText(): string
{
    $codes = app(MoveCodes::class);

    return $codes->text($codes->create(User::factory()->create()));
}

beforeEach(fn() => CloudBilling::enable());

test('starting a move pauses the team and keeps a file only that panel can open', function () {
    config(['app.url' => 'https://panel.example.com']);
    $team = teamMoveTeam();
    $codes = app(MoveCodes::class);
    $code = $codes->create(User::factory()->create());

    app(TeamMove::class)->start($team, teamMoveOwner($team), $codes->text($code));
    $team->refresh();

    expect($team->moving_at)->not->toBeNull()
        ->and($team->moving_to)->toBe('https://panel.example.com')
        ->and($team->servers()->first()->status)->toBe(ServerStatus::Moving)
        ->and(app(MoveFile::class)->open((string) $team->move_file, $code->fresh())['team']['name'])->toBe('Acme')
        ->and($team->toArray())->not->toHaveKey('move_file');
});

test('a code that is not one leaves the team as it was', function () {
    $team = teamMoveTeam();

    expect(fn() => app(TeamMove::class)->start($team, teamMoveOwner($team), 'nope'))->toThrow(ValidationException::class);

    expect($team->fresh()->moving_at)->toBeNull()
        ->and($team->servers()->first()->status)->toBe(ServerStatus::Active);
});

test('a moving team refuses changes and says why', function () {
    $team = teamMoveTeam();
    app(TeamMove::class)->start($team, teamMoveOwner($team), teamMoveCodeText());

    expect(fn() => app(PlanGate::class)->ensureWritable($team->fresh(), teamMoveOwner($team)))
        ->toThrow(PlanRefusal::class, 'This team is moving to self-hosted Coresmith, so Coresmith Cloud no longer changes it. Cancel the move in Billing settings to make changes here again.');
});

test('a teammate who cannot cancel the move is told to ask an owner', function () {
    $team = teamMoveTeam();
    app(TeamMove::class)->start($team, teamMoveOwner($team), teamMoveCodeText());

    expect(fn() => app(PlanGate::class)->ensureWritable($team->fresh(), CloudBilling::member($team)))
        ->toThrow(PlanRefusal::class, 'This team is moving to self-hosted Coresmith, so Coresmith Cloud no longer changes it. Ask an owner if it should stay here.');
});

test('a moving team is not deployed', function () {
    $team = teamMoveTeam();
    app(TeamMove::class)->start($team, teamMoveOwner($team), teamMoveCodeText());

    expect(app(DeployGuard::class)->refusal($team->applications()->first()))
        ->toBe('This team is moving to self-hosted Coresmith, so Coresmith Cloud no longer deploys it.');
});

test('cancelling the move gives the team back to coresmith cloud', function () {
    $team = teamMoveTeam();
    app(TeamMove::class)->start($team, teamMoveOwner($team), teamMoveCodeText());

    app(TeamMove::class)->cancel($team->fresh(), teamMoveOwner($team));
    $team->refresh();

    expect($team->moving_at)->toBeNull()
        ->and($team->moving_to)->toBeNull()
        ->and($team->move_file)->toBeNull()
        ->and($team->servers()->first()->status)->toBe(ServerStatus::Active)
        ->and(app(DeployGuard::class)->refusal($team->applications()->first()))->toBeNull();
});

test('a team that is not moving has nothing to cancel', function () {
    $team = teamMoveTeam();

    expect(fn() => app(TeamMove::class)->cancel($team, teamMoveOwner($team)))
        ->toThrow(ValidationException::class, 'This team is not moving, so there is nothing to cancel.');
});

test('moving a paying team ends its plan at the period end', function () {
    $team = teamMoveTeam(Team::factory()->subscribed(Plan::Pro)->owned()->create(['name' => 'Acme']));
    $subscription = $team->subscription('default');
    $item = $subscription->items()->value('stripe_id');
    FakeStripe::fake([
        FakeStripe::url("subscriptions/{$subscription->stripe_id}*") => Http::response(FakeStripe::subscription((string) $subscription->stripe_id, (string) $team->stripe_id, 'price_test_pro', 'active', ['cancel_at_period_end' => true, 'items' => ['data' => [['id' => $item]]]])),
        FakeStripe::url("subscription_items/{$item}*") => Http::response(['id' => $item, 'object' => 'subscription_item', 'current_period_end' => CarbonImmutable::parse('2026-11-18 09:00:00')->getTimestamp()]),
    ]);

    app(TeamMove::class)->start($team, teamMoveOwner($team), teamMoveCodeText());

    expect($subscription->fresh()->ends_at?->toDateTimeString())->toBe('2026-11-18 09:00:00');
});

test('cancelling the move of a paying team keeps its plan', function () {
    $team = teamMoveTeam(Team::factory()->owned()->create(['stripe_id' => 'cus_moving', 'moving_at' => now(), 'moving_to' => 'https://panel.example.com', 'move_file' => '{}']));
    $team->servers()->update(['status' => ServerStatus::Moving->value]);
    $subscription = Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->ending(now()->addDays(10))->create();
    FakeStripe::fake([
        FakeStripe::url("subscriptions/{$subscription->stripe_id}*") => Http::response(FakeStripe::subscription((string) $subscription->stripe_id, 'cus_moving', 'price_test_pro', 'active', ['items' => ['data' => [['id' => $subscription->items()->value('stripe_id')]]]])),
    ]);

    app(TeamMove::class)->cancel($team->fresh(), teamMoveOwner($team));

    expect($subscription->fresh()->ends_at)->toBeNull()
        ->and($team->fresh()->moving_at)->toBeNull();
});
