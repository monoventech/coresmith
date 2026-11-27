// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type PaletteKind = 'app' | 'server' | 'project' | 'database' | 'page' | 'action'

export type PaletteItem = {
    id: string
    kind: PaletteKind
    label: string
    hint: string
    icon: string
    href?: string
    action?: 'theme'
    keywords?: string[]
    keys?: { server: string; app?: string }
    can?: { logs?: boolean; setup?: boolean; terminal?: boolean; root?: boolean }
}

export type PaletteGroup = {
    key: string
    heading: string
    items: PaletteItem[]
    total: number
    more: number
}

export const GROUP_LIMIT = 5
export const EXPANDED_LIMIT = 50
export const DEFAULT_LIMIT = 4
export const RECENT_KEEP = 10

const ORDER: PaletteKind[] = ['app', 'server', 'project', 'database', 'page', 'action']

export const HEADINGS: Record<PaletteKind, { heading: string; noun: string }> = {
    app: { heading: 'Apps', noun: 'apps' },
    server: { heading: 'Servers', noun: 'servers' },
    project: { heading: 'Projects', noun: 'projects' },
    database: { heading: 'Databases', noun: 'databases' },
    page: { heading: 'Pages', noun: 'pages' },
    action: { heading: 'Actions', noun: 'actions' }
}

export const MACHINE: PaletteKind[] = ['app', 'server', 'database']

function words(text: string): string[] {
    return text.split(/[\s\-_./:·]+/).filter(Boolean)
}

function tier(text: string, needle: string): number {
    if (text === needle) {
        return 4
    }

    if (text.startsWith(needle)) {
        return 3
    }

    if (words(text).some((word) => word.startsWith(needle))) {
        return 2
    }

    return text.includes(needle) ? 1 : 0
}

export function score(item: PaletteItem, query: string): number {
    const needle = query.trim().toLowerCase()

    if (needle === '') {
        return 0
    }

    const label = item.label.toLowerCase()
    const direct = tier(label, needle)

    if (direct > 0) {
        return direct * 100
    }

    const keywords = (item.keywords ?? []).map((keyword) => keyword.toLowerCase())
    const best = Math.max(0, ...keywords.map((keyword) => tier(keyword, needle)))

    if (best > 0) {
        return best * 10
    }

    const tokens = needle.split(/\s+/).filter(Boolean)
    const haystack = [label, ...keywords]

    return tokens.length > 1 && tokens.every((token) => haystack.some((text) => text.includes(token))) ? 5 : 0
}

export function search(items: PaletteItem[], query: string, expanded: PaletteKind | null): PaletteGroup[] {
    const ranked = items
        .map((item) => ({ item, score: score(item, query) }))
        .filter((entry) => entry.score > 0)
        .sort(
            (a, b) =>
                b.score - a.score ||
                a.item.label.length - b.item.label.length ||
                a.item.label.localeCompare(b.item.label)
        )

    const groups = ORDER.map((kind) => {
        const matches = ranked.filter((entry) => entry.item.kind === kind)
        const limit = kind === expanded ? EXPANDED_LIMIT : GROUP_LIMIT

        return { kind, matches, limit, best: matches[0]?.score ?? 0 }
    })

    return groups
        .filter((group) => group.matches.length > 0)
        .sort((a, b) => b.best - a.best || ORDER.indexOf(a.kind) - ORDER.indexOf(b.kind))
        .map((group) => ({
            key: group.kind,
            heading: HEADINGS[group.kind].heading,
            items: group.matches.slice(0, group.limit).map((entry) => entry.item),
            total: group.matches.length,
            more: Math.max(0, group.matches.length - group.limit)
        }))
}

export type Destination = PaletteItem & { current?: boolean }

export function destinations(item: PaletteItem): Destination[] {
    const keys = item.keys

    if (item.kind === 'app' && keys?.app) {
        const at = [keys.server, keys.app]

        return [
            { ...item, id: `${item.id}:open`, kind: 'page', label: 'Open', hint: `${item.label}'s page`, keywords: [] },
            {
                id: `${item.id}:releases`,
                kind: 'page',
                label: 'Releases',
                hint: 'and rollbacks',
                icon: 'history',
                href: route('servers.applications.releases.index', at)
            },
            ...(item.can?.logs
                ? [
                      {
                          id: `${item.id}:logs`,
                          kind: 'page' as const,
                          label: 'Logs',
                          hint: 'requests and output',
                          icon: 'scroll-text',
                          href: route('servers.applications.logs', at)
                      }
                  ]
                : []),
            ...(item.can?.setup
                ? [
                      {
                          id: `${item.id}:setup`,
                          kind: 'page' as const,
                          label: 'Edit setup',
                          hint: 'build and start',
                          icon: 'settings',
                          href: route('servers.applications.setup.edit', at)
                      }
                  ]
                : []),
            ...(item.can?.terminal
                ? [
                      {
                          id: `${item.id}:terminal`,
                          kind: 'page' as const,
                          label: 'Open a terminal',
                          hint: 'in its live release',
                          icon: 'terminal',
                          href: route('servers.terminal', { server: keys.server, app: keys.app })
                      }
                  ]
                : [])
        ]
    }

    if (item.kind === 'server' && keys) {
        const tab = (id: string, label: string, hint: string, icon: string, name: string): Destination => ({
            id: `${item.id}:${id}`,
            kind: 'page',
            label,
            hint,
            icon,
            href: route(name, keys.server)
        })

        return [
            { ...item, id: `${item.id}:open`, kind: 'page', label: 'Open', hint: 'apps and runtimes', keywords: [] },
            tab('databases', 'Databases', 'and backups', 'database', 'servers.databases.index'),
            tab('network', 'Network', 'addresses and IPv4', 'network', 'servers.network'),
            tab('hardening', 'Hardening', 'firewall and checks', 'shield', 'servers.hardening'),
            tab('activity', 'Activity', 'what changed', 'activity', 'servers.activity'),
            ...(item.can?.logs ? [tab('logs', 'Logs', 'system and services', 'scroll-text', 'servers.logs')] : []),
            ...(item.can?.terminal
                ? [
                      tab(
                          'terminal',
                          'Open a terminal',
                          item.can.root ? "as root or an app's user" : "as an app's user",
                          'terminal',
                          'servers.terminal'
                      )
                  ]
                : [])
        ]
    }

    if (item.kind === 'database' && keys) {
        return [
            { ...item, id: `${item.id}:open`, kind: 'page', label: 'Open', hint: 'backups and access', keywords: [] },
            {
                id: `${item.id}:server`,
                kind: 'page',
                label: 'Its server',
                hint: item.hint.replace(/^.* on /, ''),
                icon: 'server',
                href: route('servers.show', keys.server)
            }
        ]
    }

    return []
}

export function hasDestinations(item: PaletteItem): boolean {
    return item.kind === 'app' || item.kind === 'server' || item.kind === 'database'
}

function recentKey(team: string): string {
    return `coresmith:recent:${team}`
}

export function readRecent(team: string): string[] {
    try {
        const stored = JSON.parse(window.localStorage.getItem(recentKey(team)) ?? '[]') as unknown

        return Array.isArray(stored) ? stored.filter((id): id is string => typeof id === 'string') : []
    } catch {
        return []
    }
}

export function remember(team: string, id: string): void {
    try {
        const kept = [id, ...readRecent(team).filter((each) => each !== id)].slice(0, RECENT_KEEP)

        window.localStorage.setItem(recentKey(team), JSON.stringify(kept))
    } catch {
        return
    }
}
