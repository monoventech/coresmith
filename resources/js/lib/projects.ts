// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { EntityState } from '@/lib/fleet'

export type Place = { name: string; href: string | null }

export type ProjectCard = {
    id: string
    name: string
    href: string
    meta: string
    apps: { id: string; name: string; tags: string[]; state: EntityState }[]
    latest: { application: string; when: string; href: string } | null
}

export type ProjectApp = {
    id: string
    name: string
    tags: string[]
    kind: string
    state: EntityState
    href: string
    server: Place
    domain: { label: string; href: string } | null
    last_deploy: {
        state: EntityState
        when: string
        full: string
        commit: string | null
        by: string | null
        href: string | null
    } | null
    deploy_href: string | null
    tags_href: string | null
    remove_href: string | null
}

export type ProjectDatabase = {
    id: string
    name: string
    type: string
    href: string
    server: Place
    offsite: { label: string; tone: 'success' | 'destructive' } | null
    remove_href: string | null
}

export type ProjectDeploy = {
    id: string
    application: string
    server: string
    release: string
    branch: string | null
    when: string
    full: string
    duration: string | null
    state: EntityState
    href: string | null
}

export type ProjectServer = {
    id: string
    name: string
    href: string | null
    state: EntityState | null
    cpu: number | null
    memory: number | null
    disk: number | null
}

export type ProjectAttention = {
    severity: 'critical' | 'warning'
    subject: string
    summary: string
    href: string | null
}

export type ProjectPerson = { name: string; role: string; reach: string }

export type Candidate = { id: string; name: string; where: string }

export const ENGINES: Record<string, string> = { mysql: 'MySQL', postgresql: 'PostgreSQL', redis: 'Redis' }
