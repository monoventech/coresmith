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
    environments: { id: string; name: string; apps: number }[]
    latest: { application: string; when: string; href: string } | null
}

export type EnvironmentApp = {
    id: string
    name: string
    kind: string
    state: EntityState
    href: string
    server: Place
    remove_href: string | null
}

export type EnvironmentDatabase = {
    id: string
    name: string
    type: string
    href: string
    server: Place
    remove_href: string | null
}

export type EnvironmentDeploy = {
    id: string
    application: string
    release: string
    branch: string | null
    when: string
    full: string
    state: EntityState
    href: string | null
}

export type EnvironmentView = {
    id: string
    name: string
    meta: string
    applications: EnvironmentApp[]
    databases: EnvironmentDatabase[]
    deploys: EnvironmentDeploy[]
    can: { fill: boolean }
    fill_href: string
    rename_href: string
    delete_href: string
}

export type Candidate = { id: string; name: string; environment_id: string | null; where: string }

export const ENGINES: Record<string, string> = { mysql: 'MySQL', postgresql: 'PostgreSQL', redis: 'Redis' }
