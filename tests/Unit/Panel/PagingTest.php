<?php

declare(strict_types=1);

use App\Services\Panel\Paging;
use Illuminate\Pagination\LengthAwarePaginator;

function pagingAt(int $total, int $current, int $perPage = 50): LengthAwarePaginator
{
    return new LengthAwarePaginator(range(1, max(0, min($perPage, $total - ($current - 1) * $perPage))), $total, $perPage, $current, ['path' => 'https://coresmith.test/deployments']);
}

test('a page in the middle of many shows the first, last and nearby pages, with gaps between', function () {
    $pages = Paging::present(pagingAt(500, 5));

    expect(array_map(fn(?array $link): ?int => $link['page'] ?? null, $pages['links']))->toBe([1, null, 4, 5, 6, null, 10])
        ->and($pages['links'][2]['href'])->toBe('https://coresmith.test/deployments?page=4')
        ->and($pages)->toMatchArray([
            'current' => 5,
            'last' => 10,
            'from' => 201,
            'to' => 250,
            'total' => 500,
            'newer' => 'https://coresmith.test/deployments?page=4',
            'older' => 'https://coresmith.test/deployments?page=6',
        ]);
});

test('a single page between two shown pages is shown instead of a gap', function () {
    $pages = Paging::present(pagingAt(300, 4));

    expect(array_map(fn(?array $link): ?int => $link['page'] ?? null, $pages['links']))->toBe([1, 2, 3, 4, 5, 6]);
});

test('the first page has nothing newer and the last has nothing older', function () {
    expect(Paging::present(pagingAt(61, 1)))->toMatchArray(['newer' => null, 'older' => 'https://coresmith.test/deployments?page=2', 'from' => 1, 'to' => 50])
        ->and(Paging::present(pagingAt(61, 2)))->toMatchArray(['newer' => 'https://coresmith.test/deployments?page=1', 'older' => null, 'from' => 51, 'to' => 61]);
});

test('one page of results needs no pager', function () {
    expect(Paging::present(pagingAt(12, 1)))->toMatchArray(['last' => 1, 'newer' => null, 'older' => null, 'links' => [['page' => 1, 'href' => 'https://coresmith.test/deployments?page=1']]]);
});

test('a feed without a total pages by newer and older alone', function () {
    $pages = Paging::feed(3, true, fn(int $page): string => "https://coresmith.test/servers/web-1/activity?page={$page}");

    expect($pages)->toBe([
        'current' => 3,
        'last' => null,
        'from' => null,
        'to' => null,
        'total' => null,
        'newer' => 'https://coresmith.test/servers/web-1/activity?page=2',
        'older' => 'https://coresmith.test/servers/web-1/activity?page=4',
        'links' => [],
    ])->and(Paging::feed(1, false, fn(int $page): string => "/activity?page={$page}"))->toMatchArray(['newer' => null, 'older' => null]);
});
