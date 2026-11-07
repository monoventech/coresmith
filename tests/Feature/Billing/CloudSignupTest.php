<?php

declare(strict_types=1);

use App\Enums\BillingState;
use App\Enums\ReadOnlyReason;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamBilling;
use App\Services\Billing\TrialHistory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CloudBilling;

function cloudSignup(string $email = 'maya@example.com', string $name = 'Maya Lindqvist'): TestResponse
{
    return test()->post(route('register.store'), [
        'name' => $name,
        'email' => $email,
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);
}

beforeEach(function () {
    Notification::fake();
});

test('a cloud signup gets its own team, as owner, on a 14 day trial', function () {
    CloudBilling::enable();

    cloudSignup()->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'maya@example.com')->firstOrFail();
    $team = Team::query()->sole();

    expect($team->name)->toBe("Maya's team")
        ->and($team->owner_id)->toBe($user->id)
        ->and($team->members()->whereKey($user->id)->first()?->pivot->role)->toBe(TeamRole::Owner->value)
        ->and($user->fresh()->current_team_id)->toBe($team->id)
        ->and(abs($team->trial_ends_at->diffInSeconds(now()->addDays(14))))->toBeLessThan(60)
        ->and(DB::table('trial_history')->where('email', 'maya@example.com')->where('team_id', $team->id)->exists())->toBeTrue();
});

test('an address that already had a trial starts without one', function () {
    CloudBilling::enable();
    DB::table('trial_history')->insert(['email' => 'maya@example.com', 'team_id' => null, 'started_at' => now()->subMonth()]);

    cloudSignup('Maya+again@Example.com')->assertSessionHasNoErrors();

    $team = Team::query()->sole();
    $status = app(TeamBilling::class)->status($team);

    expect($team->trial_ends_at)->toBeNull()
        ->and($status->state)->toBe(BillingState::ReadOnly)
        ->and($status->reason)->toBe(ReadOnlyReason::NoPlan)
        ->and(DB::table('trial_history')->count())->toBe(1);
});

test('the trial history outlives the account', function () {
    CloudBilling::enable();
    cloudSignup();

    Team::query()->sole()->forceDelete();
    User::query()->where('email', 'maya@example.com')->firstOrFail()->forceDelete();

    expect(DB::table('trial_history')->where('email', 'maya@example.com')->exists())->toBeTrue();
});

test('normalising an address', function (string $email, string $normalised) {
    expect(TrialHistory::normalise($email))->toBe($normalised);
})->with([
    'case, spaces and a tag' => [' Maya+billing@Example.COM ', 'maya@example.com'],
    'no tag' => ['no-plus@example.com', 'no-plus@example.com'],
]);

test('self-hosted signups stay as they were', function () {
    cloudSignup()->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'maya@example.com')->exists())->toBeTrue()
        ->and(Team::query()->count())->toBe(0)
        ->and(DB::table('trial_history')->count())->toBe(0);

    Notification::assertNothingSent();
});

test('cloud asks new people to confirm their email before the panel opens', function () {
    CloudBilling::enable();
    cloudSignup();
    $user = User::query()->where('email', 'maya@example.com')->firstOrFail();

    Notification::assertSentTo($user, VerifyEmail::class);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $this->actingAs($user)->getJson(route('dashboard'))->assertForbidden()->assertJson(['message' => 'Confirm your email address first. The link is in your inbox.']);

    $user->markEmailAsVerified();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
});

test('self-hosted never asks for email confirmation', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('the confirmation email speaks like the panel', function () {
    $mail = (new VerifyEmail)->toMail(User::factory()->unverified()->create());

    expect($mail->subject)->toBe('Confirm your email for Coresmith')
        ->and($mail->actionText)->toBe('Confirm your email')
        ->and($mail->introLines)->toBe(['Confirm this address to finish setting up your Coresmith account.'])
        ->and($mail->outroLines)->toBe(['If you did not create an account, you can ignore this email.']);
});

test('signups are limited per address', function () {
    CloudBilling::enable();
    config(['coresmith.billing.signups_per_hour' => 2]);

    cloudSignup('first@example.com')->assertSessionHasNoErrors();
    $this->post(route('logout'));
    cloudSignup('second@example.com')->assertSessionHasNoErrors();
    $this->post(route('logout'));

    cloudSignup('third@example.com')->assertSessionHasErrors(['email' => 'Too many accounts were created from this address. Try again in an hour.']);

    expect(User::query()->where('email', 'third@example.com')->exists())->toBeFalse();
});

test('the sign up page offers the trial only on cloud', function () {
    $this->get(route('register'))->assertInertia(fn($page) => $page->where('trial', null));

    CloudBilling::enable();

    $this->get(route('register'))->assertInertia(fn($page) => $page->where('trial.days', 14));
});

test('the profile knows cloud confirms email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn($page) => $page->where('mustVerifyEmail', false));

    CloudBilling::enable();

    $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn($page) => $page->where('mustVerifyEmail', true));
});

test('two tags of one address get one trial, without an error', function () {
    CloudBilling::enable();

    cloudSignup('maya+1@example.com')->assertSessionHasNoErrors();
    auth()->logout();
    cloudSignup('maya+2@example.com')->assertSessionHasNoErrors();

    expect(DB::table('trial_history')->pluck('email')->all())->toBe(['maya@example.com'])
        ->and(Team::query()->whereNotNull('trial_ends_at')->count())->toBe(1)
        ->and(Team::query()->count())->toBe(2);
});

test('claiming a trial twice grants it once and leaves the transaction usable', function () {
    $team = Team::factory()->create();
    $history = app(TrialHistory::class);

    DB::transaction(function () use ($history, $team) {
        expect($history->claim('Maya+1@Example.com', $team))->toBeTrue()
            ->and($history->claim('maya+2@example.com', $team))->toBeFalse()
            ->and(DB::table('trial_history')->count())->toBe(1);
    });

    $row = DB::table('trial_history')->sole();

    expect($row->email)->toBe('maya@example.com')
        ->and($row->team_id)->toBe($team->id)
        ->and($row->started_at)->not->toBeNull();
});

test('a trial is claimed by inserting, with no check first to race', function () {
    $team = Team::factory()->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    app(TrialHistory::class)->claim('maya@example.com', $team);

    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn(string $query): bool => str_contains($query, 'trial_history'))->values();

    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and(strtolower($queries[0]))->toStartWith('insert');
});

test('the database holds one trial per address', function () {
    $indexes = collect(Schema::getIndexes('trial_history'))->filter(fn(array $index): bool => $index['columns'] === ['email']);

    expect($indexes)->toHaveCount(1)
        ->and($indexes->first()['unique'])->toBeTrue();
});

test('addresses that only look alike are different trials', function () {
    $team = Team::factory()->create();
    $history = app(TrialHistory::class);

    expect($history->claim('jose@example.com', $team))->toBeTrue()
        ->and($history->claim('josé@example.com', $team))->toBeTrue();
});

test('the unique trial index survives addresses that already repeat', function () {
    $migration = database_path('migrations/' . collect(scandir(database_path('migrations')))->first(fn(string $file): bool => str_ends_with($file, '_allow_one_trial_per_address.php')));

    Schema::table('trial_history', function (Blueprint $table) {
        $table->dropUnique(['email']);
        $table->index('email');
    });

    $first = (string) Str::ulid();
    $only = (string) Str::ulid();

    DB::table('trial_history')->insert([
        ['email' => 'maya@example.com', 'team_id' => (string) Str::ulid(), 'started_at' => '2026-02-01 00:00:00'],
        ['email' => 'maya@example.com', 'team_id' => $first, 'started_at' => '2026-01-01 00:00:00'],
        ['email' => 'maya@example.com', 'team_id' => (string) Str::ulid(), 'started_at' => '2026-03-01 00:00:00'],
        ['email' => 'ines@example.com', 'team_id' => $only, 'started_at' => '2026-02-01 00:00:00'],
    ]);

    (require $migration)->up();

    $kept = DB::table('trial_history')->orderBy('email')->pluck('team_id', 'email')->all();

    DB::table('trial_history')->delete();

    expect($kept)->toBe(['ines@example.com' => $only, 'maya@example.com' => $first])
        ->and(collect(Schema::getIndexes('trial_history'))->filter(fn(array $index): bool => $index['columns'] === ['email'])->sole()['unique'])->toBeTrue();
});
