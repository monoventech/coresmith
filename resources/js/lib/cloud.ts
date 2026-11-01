export type CloudProviderType = 'hetzner' | 'digitalocean' | 'vultr' | 'linode'

export type CloudTypeOption = {
    value: CloudProviderType
    label: string
    help: string
    token_url: string
    permission: string
}

export type CloudAccountRow = {
    id: string
    type: CloudProviderType
    provider: string
    name: string
    label: string | null
    refused: boolean
    refused_on: string | null
    servers: number
}

export type CloudAccounts = { accounts: CloudAccountRow[]; types: CloudTypeOption[]; can_manage: boolean }
