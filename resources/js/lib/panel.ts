export type Section = 'fleet' | 'deployments' | 'releases' | 'settings' | 'setup'

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
    can: { createServer: boolean }
    live: {
        streaming: boolean
        refresh_seconds: number
        socket: { key: string; host: string; port: number; tls: boolean } | null
    }
}

export function appPath(url: string, base = ''): string {
    const pathname = new URL(url, 'http://localhost').pathname

    if (base !== '' && (pathname === base || pathname.startsWith(`${base}/`))) {
        return pathname.slice(base.length) || '/'
    }

    return pathname
}

export type PaletteCommand = {
    label: string
    icon: string
    hint: string
    href?: string
    action?: 'theme'
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

    if (path.startsWith('/dashboard') || path.startsWith('/servers') || path.startsWith('/projects')) {
        return 'fleet'
    }

    return null
}

export function isMac(): boolean {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)
}
