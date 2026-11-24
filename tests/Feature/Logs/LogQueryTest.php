<?php

declare(strict_types=1);

use App\Services\Logs\LogQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

function logQueryFrom(array $query): LogQuery
{
    return LogQuery::from(Request::create('/logs', 'GET', $query));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
});

test('an empty query reads the last hour of every source at every level', function () {
    $query = logQueryFrom([]);

    expect($query->window)->toBe('1h')
        ->and($query->search)->toBe('')
        ->and($query->sources)->toBe([])
        ->and($query->level)->toBe('all')
        ->and($query->since(now()->getTimestamp()))->toBe(now()->subHour()->getTimestamp())
        ->and($query->until())->toBeNull()
        ->and($query->wants('process:web'))->toBeTrue();
});

test('a window, level or source it does not know falls back to the defaults', function () {
    $query = logQueryFrom(['window' => '3y', 'level' => 'debug', 'sources' => ['requests', 'Rm -rf', ['x'], 'process:web']]);

    expect($query->window)->toBe('1h')
        ->and($query->level)->toBe('all')
        ->and($query->sources)->toBe(['requests', 'process:web']);
});

test('a search keeps to one line of at most 200 characters', function () {
    $query = logQueryFrom(['q' => "  order\n1042\0" . str_repeat('x', 300)]);

    expect($query->search)->toStartWith('order 1042 ')
        ->and(mb_strlen($query->search))->toBe(200);
});

test('logs around a moment read ten seconds either side of it, and only for a moment in the last 30 days', function () {
    $moment = now()->subMinutes(5)->getTimestamp();

    expect(logQueryFrom(['around' => (string) $moment])->since(now()->getTimestamp()))->toBe($moment - 10)
        ->and(logQueryFrom(['around' => (string) $moment])->until())->toBe($moment + 10)
        ->and(logQueryFrom(['around' => (string) now()->subDays(40)->getTimestamp()])->around)->toBeNull()
        ->and(logQueryFrom(['around' => '12abc'])->around)->toBeNull();
});

test('everything has no start, and longer windows read more of each file', function () {
    expect(logQueryFrom(['window' => 'all'])->since(now()->getTimestamp()))->toBeNull()
        ->and(logQueryFrom(['window' => '15m'])->bytes())->toBe(LogQuery::SHORT_BYTES)
        ->and(logQueryFrom(['window' => '7d'])->bytes())->toBe(LogQuery::LONG_BYTES);
});

test('the same window and search share a read, whatever the sources and level', function () {
    expect(logQueryFrom(['window' => '1h', 'q' => 'x', 'level' => 'errors'])->key())->toBe(logQueryFrom(['window' => '1h', 'q' => 'x', 'sources' => ['requests']])->key())
        ->and(logQueryFrom(['window' => '1h', 'q' => 'x'])->key())->not->toBe(logQueryFrom(['window' => '1h', 'q' => 'y'])->key());
});

test('the level filter keeps what it names', function () {
    expect(logQueryFrom(['level' => 'errors'])->allows('warning'))->toBeFalse()
        ->and(logQueryFrom(['level' => 'warnings'])->allows('warning'))->toBeTrue()
        ->and(logQueryFrom(['level' => 'warnings'])->allows('info'))->toBeFalse();
});
