<?php

declare(strict_types=1);

use App\Services\Runtime\Catalog;

test('lines are listed newest first', function () {
    expect(array_column(app(Catalog::class)->lines('php'), 'line'))->toBe(['8.5', '8.4', '8.3', '8.2', '8.1'])
        ->and(array_column(app(Catalog::class)->lines('nodejs'), 'line'))->toBe(['26', '24', '22', '20']);
});

test('php and ruby are tracked by minor version, node, bun and deno by major', function () {
    $catalog = app(Catalog::class);

    expect($catalog->lineOf('php', '8.4.25'))->toBe('8.4')
        ->and($catalog->lineOf('ruby', '3.3.12'))->toBe('3.3')
        ->and($catalog->lineOf('nodejs', '24.21.0'))->toBe('24')
        ->and($catalog->lineOf('nodejs', 'v22.16.0'))->toBe('22')
        ->and($catalog->lineOf('bun', '1.4.2'))->toBe('1')
        ->and($catalog->lineOf('php', 'nightly'))->toBeNull();
});

test('each runtime names its recommended line, its newest and its oldest', function () {
    $catalog = app(Catalog::class);

    expect($catalog->recommendedLine('php'))->toBe('8.5')
        ->and($catalog->recommendedLine('nodejs'))->toBe('24')
        ->and($catalog->newestLine('nodejs'))->toBe('26')
        ->and($catalog->oldestLine('php'))->toBe('8.1')
        ->and($catalog->range('php'))->toBe('8.1–8.5')
        ->and($catalog->range('bun'))->toBe('1');
});

test('the newest patch of each line is what gets installed', function () {
    expect(app(Catalog::class)->versions('php'))->toBe(['8.5.10', '8.4.25', '8.3.33', '8.2.33', '8.1.34'])
        ->and(app(Catalog::class)->line('ruby', '3.3'))->toBe(['line' => '3.3', 'latest' => '3.3.12', 'active_until' => '2026-03-31', 'eol_at' => '2027-03-31'])
        ->and(app(Catalog::class)->line('ruby', '2.7'))->toBeNull();
});

test('every runtime Coresmith installs has a label and at least one line', function (string $runtime) {
    expect(app(Catalog::class)->label($runtime))->not->toBe($runtime)
        ->and(app(Catalog::class)->lines($runtime))->not->toBe([]);
})->with(Catalog::RUNTIMES);
