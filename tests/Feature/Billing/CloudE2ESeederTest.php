<?php

declare(strict_types=1);

use App\Enums\BillingState;
use App\Enums\Plan;
use App\Models\ManagedDatabase;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Billing\TeamBilling;
use App\Services\Billing\TrialHistory;
use App\Services\Teams\AuditLog;
use App\Services\Teams\TeamDeletion;
use Database\Seeders\CloudE2ESeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CloudBilling;

test('the cloud browser seed has a team on an older price', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $team = Team::query()->where('slug', 'old-price')->firstOrFail();

    expect($team->subscription('default')->unit_amount)->toBe(1500)
        ->and(app(TeamBilling::class)->status($team)->plan)->toBe(Plan::Pro);
});

test('the cloud browser seed has a paying team and a team on its trial', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $monoven = Team::query()->where('slug', 'monoven')->firstOrFail();
    $trial = Team::query()->where('slug', 'trial')->firstOrFail();
    $owner = User::query()->where('email', CloudE2ESeeder::OWNER_EMAIL)->firstOrFail();
    $paying = app(TeamBilling::class)->status($monoven);
    $trying = app(TeamBilling::class)->status($trial);

    expect($paying->state)->toBe(BillingState::Active)
        ->and($paying->plan)->toBe(Plan::Pro)
        ->and($monoven->pm_last_four)->toBe('4242')
        ->and($monoven->pm_expires)->toBe('08/28')
        ->and($trying->state)->toBe(BillingState::Trialing)
        ->and($trying->plan)->toBe(Plan::Trial)
        ->and($trying->trialDaysLeft())->toBe(9)
        ->and($trial->servers()->pluck('name')->all())->toBe(['forge-trial-01'])
        ->and($owner->teams()->pluck('slug')->reject(fn(string $slug): bool => str_contains($slug, '-'))->sort()->values()->all())->toBe(['atelier', 'ending', 'expired', 'fleet', 'monoven', 'overdue', 'solo', 'trial'])
        ->and($owner->teams()->count())->toBe(11 + 6 * count(CloudE2ESeeder::PROJECTS))
        ->and($owner->current_team_id)->toBe($monoven->id);
});

test('the cloud browser seed has a lapsed team, one about to lapse and one that cannot pay', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $status = fn(string $slug) => app(TeamBilling::class)->status(Team::query()->where('slug', $slug)->firstOrFail());
    $expired = Team::query()->where('slug', 'expired')->firstOrFail();
    $member = User::query()->where('email', CloudE2ESeeder::MEMBER_EMAIL)->firstOrFail();

    expect($status('expired')->readOnly())->toBeTrue()
        ->and($expired->applications()->pluck('name')->all())->toBe(['lapsed-app'])
        ->and($expired->servers()->where('name', 'not like', 'lapsed-rekeyed-%')->pluck('name')->all())->toBe(['lapsed-01'])
        ->and($expired->servers()->whereNotNull('pending_host_key_fingerprint')->count())->toBe(count(CloudE2ESeeder::PROJECTS))
        ->and($expired->members()->whereKey($member->id)->first()?->pivot->role)->toBe('member')
        ->and($status('ending')->trialDaysLeft())->toBe(2)
        ->and($status('overdue')->state)->toBe(BillingState::PastDue)
        ->and(Team::query()->where('slug', 'overdue')->firstOrFail()->billingInvoices()->where('status', 'open')->count())->toBe(1);
});

test('the cloud browser seed has a full starter team', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $solo = Team::query()->where('slug', 'solo')->firstOrFail();
    $status = app(TeamBilling::class)->status($solo);

    expect($status->plan)->toBe(Plan::Starter)
        ->and($status->serverLimit())->toBe(3)
        ->and($solo->servers()->count())->toBe(3)
        ->and($solo->members()->count())->toBe(1);
});

test('the cloud browser seed gives monoven four paid invoices', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $monoven = Team::query()->where('slug', 'monoven')->firstOrFail();

    expect($monoven->billingInvoices()->pluck('number')->all())->toBe(['MNV-0004', 'MNV-0003', 'MNV-0002', 'MNV-0001'])
        ->and($monoven->billingInvoices()->where('status', 'paid')->count())->toBe(4);
});

test('the cloud browser seed gives every browser its own plan change teams', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $status = fn(string $slug) => app(TeamBilling::class)->status(Team::query()->where('slug', $slug)->firstOrFail());

    foreach (CloudE2ESeeder::PROJECTS as $code) {
        $swap = Team::query()->where('slug', "swap-{$code}")->firstOrFail();

        expect($status("pay-{$code}")->state)->toBe(BillingState::Trialing)
            ->and($status("pay-{$code}")->plan)->toBe(Plan::Trial)
            ->and($status("swap-{$code}")->state)->toBe(BillingState::Active)
            ->and($status("end-{$code}")->plan)->toBe(Plan::Pro)
            ->and($status("keep-{$code}")->state)->toBe(BillingState::Ending)
            ->and($swap->stripe_id)->toBe("cus_e2e_swap_{$code}")
            ->and($swap->subscription('default')->stripe_id)->toBe("sub_e2e_swap_{$code}")
            ->and($swap->subscription('default')->items()->pluck('stripe_id')->all())->toBe(["si_e2e_swap_{$code}"]);
    }
});

test('the cloud browser seed has a fleet too big for starter', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $fleet = Team::query()->where('slug', 'fleet')->firstOrFail();

    expect(app(TeamBilling::class)->status($fleet)->plan)->toBe(Plan::Pro)
        ->and($fleet->servers()->count())->toBe(5)
        ->and($fleet->members()->count())->toBe(3)
        ->and($fleet->domain?->hostname)->toBe('deploy.bigfleet.test')
        ->and($fleet->domain?->isVerified())->toBeTrue();
});

test('the cloud browser seed has a business team with a history', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $atelier = Team::query()->where('slug', 'atelier')->firstOrFail();

    expect(app(TeamBilling::class)->status($atelier)->plan)->toBe(Plan::Business)
        ->and($atelier->servers()->pluck('name')->all())->toBe(['atelier-01'])
        ->and(iterator_to_array(app(AuditLog::class)->rows($atelier, null), false))->toHaveCount(3);
});

test('the cloud browser seed has a business team sending its databases to s3', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $atelier = Team::query()->where('slug', 'atelier')->firstOrFail();
    $databases = ManagedDatabase::query()->where('team_id', $atelier->getKey())->orderBy('name')->get()->keyBy('name');

    expect($atelier->backupDestination?->bucket)->toBe('atelier-backups')
        ->and($databases['atelier_main']->last_offsite_at)->not->toBeNull()
        ->and($databases['atelier_main']->last_offsite_key)->toStartWith('nightly/atelier/atelier-01/atelier_main/')
        ->and($databases['atelier_legacy']->last_offsite_error)->toContain('403');

    foreach (CloudE2ESeeder::PROJECTS as $code) {
        $vault = Team::query()->where('slug', "vault-{$code}")->firstOrFail();

        expect(app(TeamBilling::class)->status($vault)->plan)->toBe(Plan::Business)
            ->and($vault->backupDestination)->toBeNull()
            ->and(ManagedDatabase::query()->where('team_id', $vault->getKey())->pluck('name')->all())->toBe(['vault_main']);
    }
});

test('the cloud browser seed has an emailed invitation to monoven for every browser', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    foreach (CloudE2ESeeder::PROJECTS as $code) {
        $invitation = TeamInvitation::usable(CloudE2ESeeder::invitationToken($code));

        expect($invitation?->team?->slug)->toBe('monoven')
            ->and($invitation?->email)->toBe("joiner-{$code}@cloud.test")
            ->and($invitation?->link_offered)->toBeFalse()
            ->and(User::findByEmail("joiner-{$code}@cloud.test"))->toBeNull();
    }
});

test('the cloud browser seed gives every browser an owner alone on a trial team it can delete', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    foreach (CloudE2ESeeder::PROJECTS as $code) {
        $leaver = User::query()->where('email', CloudE2ESeeder::leaverEmail($code))->firstOrFail();
        $team = Team::query()->where('slug', "parting-{$code}")->firstOrFail();

        expect($leaver->teams()->pluck('teams.id')->all())->toBe([$team->id])
            ->and($leaver->current_team_id)->toBe($team->id)
            ->and($team->owner_id)->toBe($leaver->id)
            ->and(app(TeamBilling::class)->status($team)->state)->toBe(BillingState::Trialing)
            ->and(app(TeamDeletion::class)->refusal($team))->toBeNull()
            ->and($team->servers()->orderBy('name')->pluck('name')->all())->toBe(["parting-{$code}-01", "parting-{$code}-02"])
            ->and($team->servers()->whereNotNull('cloud_provider_id')->whereNotNull('provider_server_id')->count())->toBe(1)
            ->and(app(TrialHistory::class)->used($leaver->email))->toBeTrue()
            ->and(Hash::check(CloudE2ESeeder::PASSWORD, $leaver->password))->toBeTrue();
    }
});

test('the cloud browser seed has a team whose plan renews, one with other members and one its owner can delete', function () {
    CloudBilling::enable();

    $this->seed(CloudE2ESeeder::class);

    $refusal = fn(string $slug): ?string => app(TeamDeletion::class)->refusal(Team::query()->where('slug', $slug)->firstOrFail());

    expect($refusal('monoven'))->toBe('End the plan from Billing first, then delete the team.')
        ->and($refusal('expired'))->toBe('Remove the other members first, so nobody loses access by surprise.')
        ->and($refusal('trial'))->toBeNull()
        ->and(Team::query()->where('slug', 'trial')->firstOrFail()->servers()->count())->toBe(1);
});
