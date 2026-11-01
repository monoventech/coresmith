<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamSsoConnection;
use App\Services\Billing\PlanChangeGuard;
use Tests\Support\CloudBilling;

function planChangeGuard(): PlanChangeGuard
{
    return app(PlanChangeGuard::class);
}

beforeEach(function () {
    CloudBilling::enable();
});

test('a team with more servers than the plan includes has to detach some first', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $servers = collect(['web-1', 'web-2', 'web-3', 'web-4', 'web-5'])
        ->map(fn(string $name) => Server::factory()->for($team)->create(['name' => $name, 'status' => ServerStatus::Active]));

    expect(planChangeGuard()->blockers($team, Plan::Starter))->toBe([[
        'message' => 'Starter includes 3 servers and this team has 5. Detach 2 first.',
        'links' => $servers->map(fn(Server $server) => ['label' => $server->name, 'href' => route('servers.show', $server)])->all(),
    ]]);
});

test('detached servers do not count against the plan', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    Server::factory()->for($team)->count(4)->create(['status' => ServerStatus::Active]);
    $team->servers()->first()->delete();

    expect(planChangeGuard()->blockers($team, Plan::Starter))->toBe([]);
});

test('starter is for one person, so the other members go first', function (int $others, string $message) {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    foreach (range(1, $others) as $ignored) {
        CloudBilling::member($team, TeamRole::Member);
    }

    expect(planChangeGuard()->blockers($team, Plan::Starter))->toBe([[
        'message' => $message,
        'links' => [['label' => 'Team settings', 'href' => route('teams.index')]],
    ]]);
})->with([
    'two others' => [2, 'Starter is for one person. Remove the other 2 members first.'],
    'one other' => [1, 'Starter is for one person. Remove the other member first.'],
]);

test('a custom domain goes before a plan without one', function (bool $verified) {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $domain = TeamDomain::factory()->for($team)->create(['hostname' => 'deploy.example.test']);

    if ($verified) {
        $domain->forceFill(['verified_at' => now()])->save();
    }

    expect(planChangeGuard()->blockers($team, Plan::Starter))->toBe([[
        'message' => 'Starter has no custom domain. Remove deploy.example.test first.',
        'links' => [['label' => 'Team settings', 'href' => route('teams.index')]],
    ]]);
})->with(['verified' => [true], 'waiting for dns' => [false]]);

test('single sign-on goes before a plan without it', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    TeamSsoConnection::factory()->for($team)->create();

    expect(planChangeGuard()->blockers($team, Plan::Pro))->toBe([[
        'message' => 'Pro has no single sign-on. Remove it first, once everyone can sign in with a password.',
        'links' => [['label' => 'Team settings', 'href' => route('teams.index')]],
    ]]);
});

test('every blocker is listed, in order', function () {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();
    Server::factory()->for($team)->count(4)->create(['status' => ServerStatus::Active]);
    CloudBilling::member($team, TeamRole::Admin);
    TeamDomain::factory()->for($team)->create(['hostname' => 'deploy.example.test']);
    TeamSsoConnection::factory()->for($team)->create();

    expect(array_column(planChangeGuard()->blockers($team, Plan::Starter), 'message'))->toBe([
        'Starter includes 3 servers and this team has 4. Detach 1 first.',
        'Starter is for one person. Remove the other member first.',
        'Starter has no custom domain. Remove deploy.example.test first.',
        'Starter has no single sign-on. Remove it first, once everyone can sign in with a password.',
    ]);
});

test('leaving business says what stops', function (Plan $plan) {
    $team = Team::factory()->subscribed(Plan::Business)->owned()->create();

    expect(planChangeGuard()->notes($team, $plan))->toBe(['Database backups to S3 stop.', 'Audit log export goes away.']);
})->with([Plan::Pro, Plan::Starter]);

test('plans without business features lose nothing by moving', function () {
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $trial = Team::factory()->onTrial()->owned()->create();
    $lapsed = Team::factory()->trialEnded()->owned()->create();

    expect(planChangeGuard()->notes($pro, Plan::Starter))->toBe([])
        ->and(planChangeGuard()->notes($trial, Plan::Starter))->toBe([])
        ->and(planChangeGuard()->notes($lapsed, Plan::Starter))->toBe([])
        ->and(planChangeGuard()->notes(Team::factory()->subscribed(Plan::Business)->owned()->create(), Plan::Business))->toBe([]);
});

test('moving up is never blocked', function () {
    $team = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    Server::factory()->for($team)->count(3)->create(['status' => ServerStatus::Active]);
    $busy = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    Server::factory()->for($busy)->count(8)->create(['status' => ServerStatus::Active]);
    CloudBilling::member($busy, TeamRole::Admin);
    TeamDomain::factory()->for($busy)->create();

    expect(planChangeGuard()->blockers($team, Plan::Pro))->toBe([])
        ->and(planChangeGuard()->blockers($team, Plan::Business))->toBe([])
        ->and(planChangeGuard()->blockers($busy, Plan::Business))->toBe([]);
});
