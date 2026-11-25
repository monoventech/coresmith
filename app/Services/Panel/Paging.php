<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use Closure;
use Illuminate\Pagination\LengthAwarePaginator;

final class Paging
{
    /**
     * @param  LengthAwarePaginator<array-key, mixed>  $paginator
     * @return array{current: int, last: int, from: int|null, to: int|null, total: int, newer: string|null, older: string|null, links: list<array{page: int, href: string}|null>}
     */
    public static function present(LengthAwarePaginator $paginator): array
    {
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $shown = array_values(array_unique(array_filter([1, $current - 1, $current, $current + 1, $last], fn(int $page): bool => $page >= 1 && $page <= $last)));
        sort($shown);

        $links = [];
        $previous = null;

        foreach ($shown as $page) {
            if ($previous !== null && $page - $previous === 2) {
                $links[] = ['page' => $previous + 1, 'href' => $paginator->url($previous + 1)];
            } elseif ($previous !== null && $page - $previous > 2) {
                $links[] = null;
            }

            $links[] = ['page' => $page, 'href' => $paginator->url($page)];
            $previous = $page;
        }

        return [
            'current' => $current,
            'last' => $last,
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'newer' => $paginator->previousPageUrl(),
            'older' => $paginator->nextPageUrl(),
            'links' => $links,
        ];
    }

    /**
     * @param  Closure(int): string  $url
     * @return array{current: int, last: null, from: null, to: null, total: null, newer: string|null, older: string|null, links: list<never>}
     */
    public static function feed(int $current, bool $more, Closure $url): array
    {
        return [
            'current' => $current,
            'last' => null,
            'from' => null,
            'to' => null,
            'total' => null,
            'newer' => $current > 1 ? $url($current - 1) : null,
            'older' => $more ? $url($current + 1) : null,
            'links' => [],
        ];
    }

    public static function page(mixed $value): int
    {
        return is_numeric($value) ? max(1, (int) $value) : 1;
    }
}
