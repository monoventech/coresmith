const CLOUDFLARE_TUNNEL_WRITE = 'c07321b023e944ff818fec44d8203567'

const tunnelPermissions = [
    { key: CLOUDFLARE_TUNNEL_WRITE, type: 'edit' },
    { key: 'dns', type: 'edit' }
]

export const tunnelTokenLink = `https://dash.cloudflare.com/?to=/:account/api-tokens&permissionGroupKeys=${encodeURIComponent(JSON.stringify(tunnelPermissions))}&name=Coresmith`
