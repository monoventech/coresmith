<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamInvoice;
use App\Services\Billing\InvoiceMirror;
use Carbon\CarbonImmutable;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

function invoiceMirror(): InvoiceMirror
{
    return app(InvoiceMirror::class);
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00'));
});

test('drafts are ignored', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);

    expect(invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'draft']), now()->getTimestamp()))->toBeNull()
        ->and(TeamInvoice::query()->count())->toBe(0);
});

test('an invoice is stored with its amounts and addresses', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $paidAt = CarbonImmutable::parse('2026-10-01 08:00:00');

    $invoice = invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', [
        'number' => 'MNV-0001',
        'total' => 3900,
        'currency' => 'USD',
        'attempt_count' => 1,
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/in_1',
        'invoice_pdf' => 'https://pay.stripe.com/invoice/acct_1/in_1/pdf',
        'status_transitions' => ['paid_at' => $paidAt->getTimestamp()],
    ]), now()->getTimestamp());

    expect($invoice?->team_id)->toBe($team->getKey())
        ->and($invoice?->stripe_id)->toBe('in_1')
        ->and($invoice?->number)->toBe('MNV-0001')
        ->and($invoice?->status)->toBe('paid')
        ->and($invoice?->total)->toBe(3900)
        ->and($invoice?->currency)->toBe('usd')
        ->and($invoice?->paid_at?->toDateTimeString())->toBe('2026-10-01 08:00:00')
        ->and($invoice?->attempt_count)->toBe(1)
        ->and($invoice?->next_attempt_at)->toBeNull()
        ->and($invoice?->hosted_url)->toBe('https://invoice.stripe.com/i/acct_1/in_1')
        ->and($invoice?->pdf_url)->toBe('https://pay.stripe.com/invoice/acct_1/in_1/pdf')
        ->and($invoice?->stripe_updated_at?->toDateTimeString())->toBe('2026-10-18 12:00:00');
});

test('a credit is stored as a negative total', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);

    expect(invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['total' => -150]), now()->getTimestamp())?->fresh()->total)->toBe(-150);
});

test('the period comes from the first line', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $line = CarbonImmutable::parse('2026-09-01 00:00:00')->getTimestamp();
    $period = CarbonImmutable::parse('2026-08-01 00:00:00')->getTimestamp();
    $created = CarbonImmutable::parse('2026-07-01 00:00:00')->getTimestamp();

    $fromLine = invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['period_start' => $period, 'lines' => ['data' => [['period' => ['start' => $line, 'end' => $line + 2_592_000]]]]]), now()->getTimestamp());
    $fromPeriod = invoiceMirror()->record($team, FakeStripe::invoice('in_2', 'cus_1', ['period_start' => $period]), now()->getTimestamp());
    $fromCreated = invoiceMirror()->record($team, array_diff_key(FakeStripe::invoice('in_3', 'cus_1', ['created' => $created]), ['period_start' => true]), now()->getTimestamp());

    expect($fromLine?->period_start?->toDateString())->toBe('2026-09-01')
        ->and($fromPeriod?->period_start?->toDateString())->toBe('2026-08-01')
        ->and($fromCreated?->period_start?->toDateString())->toBe('2026-07-01');
});

test('an older event never overwrites a newer one', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'paid']), $now);
    $kept = invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'open', 'status_transitions' => ['paid_at' => null]]), $now - 60);

    expect($kept?->status)->toBe('paid')
        ->and(TeamInvoice::query()->where('stripe_id', 'in_1')->value('status'))->toBe('paid')
        ->and(TeamInvoice::query()->count())->toBe(1);
});

test('an older event of the same kind never overwrites a newer one', function (array $newer, array $older) {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', $newer), $now);
    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', $older), $now - 60);

    $row = TeamInvoice::query()->sole();

    expect($row->status)->toBe($newer['status'])
        ->and($row->attempt_count)->toBe($newer['attempt_count']);
})->with([
    'a retry' => [['status' => 'open', 'attempt_count' => 2, 'status_transitions' => ['paid_at' => null]], ['status' => 'open', 'attempt_count' => 1, 'status_transitions' => ['paid_at' => null]]],
    'a finished invoice' => [['status' => 'paid', 'attempt_count' => 1], ['status' => 'void', 'attempt_count' => 1, 'status_transitions' => ['paid_at' => null]]],
]);

test('a newer event wins over a further-along older one', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'paid', 'attempt_count' => 2]), $now - 60);
    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'void', 'attempt_count' => 1, 'status_transitions' => ['paid_at' => null]]), $now);

    expect(TeamInvoice::query()->sole()->status)->toBe('void');
});

test('a newer event updates the invoice it already has', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'open', 'status_transitions' => ['paid_at' => null]]), $now - 60);
    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'paid']), $now);

    expect(TeamInvoice::query()->where('stripe_id', 'in_1')->value('status'))->toBe('paid')
        ->and(TeamInvoice::query()->count())->toBe(1);
});

test('invoice events from the same second keep the further-along state in either order', function (array $one, array $other, string $status, int $attempts) {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);

    foreach ([[$one, $other], [$other, $one]] as $index => $order) {
        foreach ($order as $overrides) {
            invoiceMirror()->record($team, FakeStripe::invoice("in_{$index}", 'cus_1', $overrides), now()->getTimestamp());
        }

        $row = TeamInvoice::query()->where('stripe_id', "in_{$index}")->sole();

        expect($row->status)->toBe($status)
            ->and($row->attempt_count)->toBe($attempts);
    }
})->with([
    'finalized and paid' => [['status' => 'open', 'attempt_count' => 0, 'status_transitions' => ['paid_at' => null]], ['status' => 'paid', 'attempt_count' => 1], 'paid', 1],
    'finalized and failed' => [['status' => 'open', 'attempt_count' => 0, 'status_transitions' => ['paid_at' => null]], ['status' => 'open', 'attempt_count' => 1, 'status_transitions' => ['paid_at' => null]], 'open', 1],
    'open and void' => [['status' => 'open', 'attempt_count' => 0, 'status_transitions' => ['paid_at' => null]], ['status' => 'void', 'attempt_count' => 0, 'status_transitions' => ['paid_at' => null]], 'void', 0],
    'open and written off' => [['status' => 'open', 'attempt_count' => 4, 'status_transitions' => ['paid_at' => null]], ['status' => 'uncollectible', 'attempt_count' => 4, 'status_transitions' => ['paid_at' => null]], 'uncollectible', 4],
    'written off and paid late' => [['status' => 'uncollectible', 'attempt_count' => 4, 'status_transitions' => ['paid_at' => null]], ['status' => 'paid', 'attempt_count' => 4], 'paid', 4],
]);

test('a paid invoice keeps when it was paid against a same-second open event', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'paid', 'status_transitions' => ['paid_at' => $now]]), $now);
    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'open', 'attempt_count' => 0, 'status_transitions' => ['paid_at' => null]]), $now);

    expect(TeamInvoice::query()->sole()->paid_at?->getTimestamp())->toBe($now);
});

test('an open invoice never overwrites a finished one, even when it is newer', function (string $finished) {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => $finished]), $now);
    $kept = invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'open', 'status_transitions' => ['paid_at' => null]]), $now + 60);

    expect($kept?->status)->toBe($finished)
        ->and(TeamInvoice::query()->sole()->status)->toBe($finished);
})->with(['paid', 'void', 'uncollectible']);

test('a written-off invoice can still be paid later', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'uncollectible', 'status_transitions' => ['paid_at' => null]]), $now);
    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'paid']), $now + 60);

    expect(TeamInvoice::query()->sole()->status)->toBe('paid');
});

test('same-second events in the same state apply in the order they arrive', function () {
    $team = Team::factory()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'open', 'attempt_count' => 1, 'next_payment_attempt' => $now + 3600, 'status_transitions' => ['paid_at' => null]]), $now);
    invoiceMirror()->record($team, FakeStripe::invoice('in_1', 'cus_1', ['status' => 'open', 'attempt_count' => 1, 'next_payment_attempt' => $now + 7200, 'status_transitions' => ['paid_at' => null]]), $now);

    expect(TeamInvoice::query()->sole()->next_attempt_at?->getTimestamp())->toBe($now + 7200);
});
