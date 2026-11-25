// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.
import type { ExplorerApp, ExplorerProps } from '@/lib/panel'
import { ENGINES } from '@/lib/projects'

export const FILTER_LIMIT = 50

export type ExplorerEntry = {
    key: string
    kind: 'app' | 'database' | 'server' | 'project'
    name: string
    href: string
    tone: string
    note: string
}

export function appNote(app: ExplorerApp): string | null {
    return app.state.key === 'live' ? null : app.state.label.toLowerCase()
}

function app(each: ExplorerApp): ExplorerEntry {
    return {
        key: `app:${each.id}`,
        kind: 'app',
        name: each.name,
        href: each.href,
        tone: each.state.tone,
        note: appNote(each) ?? (each.server ? `app · ${each.server}` : 'app')
    }
}

export function explorerEntries(explorer: ExplorerProps): ExplorerEntry[] {
    return [
        ...explorer.projects.flatMap((project) => [
            {
                key: `project:${project.id}`,
                kind: 'project' as const,
                name: project.name,
                href: project.href,
                tone: '',
                note: `project · ${project.apps.length} ${project.apps.length === 1 ? 'app' : 'apps'}`
            },
            ...project.apps.map(app),
            ...project.databases.map((database) => ({
                key: `database:${database.id}`,
                kind: 'database' as const,
                name: database.name,
                href: database.href,
                tone: '',
                note: `${ENGINES[database.engine] ?? database.engine} database`
            }))
        ]),
        ...explorer.unfiled.map(app),
        ...explorer.servers.map((server) => ({
            key: `server:${server.id}`,
            kind: 'server' as const,
            name: server.name,
            href: server.href,
            tone: server.state.tone,
            note: server.state.key === 'healthy' ? 'server' : server.note
        }))
    ]
}

export function filterEntries(entries: ExplorerEntry[], query: string): ExplorerEntry[] {
    const needle = query.trim().toLowerCase()

    if (needle === '') {
        return []
    }

    return entries
        .map((entry) => ({ entry, at: entry.name.toLowerCase().indexOf(needle) }))
        .filter(({ at }) => at >= 0)
        .sort(
            (a, b) =>
                a.at - b.at || a.entry.name.length - b.entry.name.length || a.entry.name.localeCompare(b.entry.name)
        )
        .map(({ entry }) => entry)
}

export function highlight(name: string, query: string): [string, string, string] {
    const needle = query.trim().toLowerCase()
    const at = needle === '' ? -1 : name.toLowerCase().indexOf(needle)

    return at < 0
        ? [name, '', '']
        : [name.slice(0, at), name.slice(at, at + needle.length), name.slice(at + needle.length)]
}
