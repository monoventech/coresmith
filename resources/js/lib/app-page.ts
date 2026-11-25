// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.
import type { EntityState } from '@/lib/fleet'

export type ProcessRow = {
    key: string
    label: string
    description: string
    toggle: string | null
    state: EntityState | null
    memory: string | null
    since: string | null
    restarts: number
    restart_href: string | null
    log_href: string | null
}

export type RuntimeRow = {
    runtime: string
    name: string
    version: string
    status: { label: string; tone: 'success' | 'warning' | 'destructive' | 'default' }
    source: string
    newest: string | null
    pinned: string | null
    update: { label: string; state: 'installing' | 'failed'; reason: string | null } | null
    update_href: string | null
}

export type RecentDeploy = {
    id: string
    release: string | null
    branch: string | null
    when: string | null
    full: string | null
    duration: string | null
    state: EntityState
    href: string
}

export type PageSection = { id: string; label: string; phone?: boolean }

export type Switchable = {
    key: string
    on: boolean
    busy_on: string
    busy_off: string
    disabled: boolean
    starting: boolean
    failure: string | null
}
