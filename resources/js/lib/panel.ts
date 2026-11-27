export type Section = 'fleet' | 'deployments' | 'releases' | 'audit-log' | 'settings' | 'setup'

export type PanelTeam = {
    id: string
    name: string
    slug: string
    role: string | null
    servers: number
    members: number
    current: boolean
}

export type PanelProps = {
    team: { id: string; name: string; slug: string; role: string | null } | null
    teams: PanelTeam[]
    base: string
    viaHost: boolean
    setup: { complete: boolean; current: number; total: number } | null
    attention: number
    can: { createServer: boolean; auditLog: boolean }
    live: {
        streaming: boolean
        refresh_seconds: number
        socket: { key: string; host: string; port: number; tls: boolean } | null
    }
}

export type StatusTone = { key: string; label: string; tone: string }

export type ExplorerApp = {
    id: string
    name: string
    tags: string[]
    href: string
    state: StatusTone
    server: string | null
}

export type ExplorerDatabase = { id: string; name: string; href: string; engine: string }

export type ExplorerProject = {
    id: string
    name: string
    href: string
    deploying: boolean
    apps: ExplorerApp[]
    databases: ExplorerDatabase[]
}

export type ExplorerServer = { id: string; name: string; href: string; state: StatusTone; note: string; tags: string[] }

export type ExplorerRunning = {
    application: string
    server: string | null
    href: string
    phase: string
    states: string[]
    started_at: string | null
    more: number
}

export type AttentionItem = { severity: string; subject: string; summary: string; href: string }

export type ExplorerProps = {
    team: { name: string; detail: string }
    projects: ExplorerProject[]
    unfiled: ExplorerApp[]
    servers: ExplorerServer[]
    running: ExplorerRunning | null
    attention: AttentionItem[]
    can: { createProject: boolean; createServer: boolean; createApplication: boolean }
}

export type CrumbSwitch = { kind: 'server' | 'application' | 'project'; id: string }

export type Crumb = { label: string; href: string | null; mono: boolean; switch: CrumbSwitch | null }

export type AppDeploy = { name: string; href: string; from: string; running_href: string | null; blocked: boolean }

export function statusDot(tone: string): string {
    switch (tone) {
        case 'success':
            return 'bg-success'
        case 'accent':
            return 'status-dot-working bg-primary'
        case 'warning':
            return 'status-dot-pulse bg-warning'
        case 'destructive':
            return 'bg-destructive'
        default:
            return 'bg-muted-foreground/60'
    }
}

export function pathOf(href: string): string {
    return new URL(href, 'http://localhost').pathname
}

export function appPath(url: string, base = ''): string {
    const pathname = new URL(url, 'http://localhost').pathname

    if (base !== '' && (pathname === base || pathname.startsWith(`${base}/`))) {
        return pathname.slice(base.length) || '/'
    }

    return pathname
}

export function sectionFor(path: string): Section | null {
    if (path.startsWith('/setup')) {
        return 'setup'
    }

    if (path.startsWith('/settings')) {
        return 'settings'
    }

    if (path.startsWith('/deployments') || /^\/servers\/[^/]+\/applications\/[^/]+\/deployments\/[^/]+/.test(path)) {
        return 'deployments'
    }

    if (path.startsWith('/releases') || /^\/servers\/[^/]+\/applications\/[^/]+\/releases/.test(path)) {
        return 'releases'
    }

    if (path.startsWith('/audit-log')) {
        return 'audit-log'
    }

    if (path.startsWith('/dashboard') || path.startsWith('/servers') || path.startsWith('/projects')) {
        return 'fleet'
    }

    return null
}

export function isMac(): boolean {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)
}
