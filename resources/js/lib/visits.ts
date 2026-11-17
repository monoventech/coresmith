// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { VisitOptions } from '@inertiajs/core'

export function replaceOnRefresh(href: string, options: VisitOptions): VisitOptions {
    const target = new URL(href, window.location.href)
    const data = options.data

    const refresh =
        (options.method ?? 'get') === 'get' &&
        (data === undefined || (!(data instanceof FormData) && Object.keys(data).length === 0)) &&
        target.origin === window.location.origin &&
        target.pathname === window.location.pathname &&
        target.search === window.location.search

    return refresh ? { replace: true } : {}
}
