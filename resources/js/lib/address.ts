import type { DnsRecord } from '@/lib/dns'

export type Address = {
    host: string
    url: string
    certificate: 'public' | 'internal' | 'tailscale'
    managed: boolean
    ipv6_only: boolean
    change: { state: 'running' | 'failed' | 'done'; from: string; to: string; error: string | null } | null
    check: {
        host: string
        ok: boolean
        problems: string[]
        notes: string[]
        records?: DnsRecord[]
        checked_at: string
    } | null
    reach: {
        host: string
        ready: boolean | null
        problems: string[]
        notes: string[]
        records: DnsRecord[]
        checked_at: string
    } | null
    cloudflare: { connected: boolean; host: string | null; error: string | null; synced: string | null }
    follow: { github: string | null; sso: string | null } | null
}
