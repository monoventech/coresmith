// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type Pages = {
    current: number
    last: number | null
    from: number | null
    to: number | null
    total: number | null
    newer: string | null
    older: string | null
    links: Array<{ page: number; href: string } | null>
}
