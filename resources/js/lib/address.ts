export type Address = {
    host: string
    url: string
    certificate: 'public' | 'internal' | 'tailscale'
    managed: boolean
    change: { state: 'running' | 'failed' | 'done'; from: string; to: string; error: string | null } | null
    follow: { github: string | null; sso: string | null } | null
}
