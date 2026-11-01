// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

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

export type CloudChoices = {
    accounts: CloudAccountRow[]
    types: CloudTypeOption[]
    selected: string
    can_connect: boolean
}

export type CloudRegion = { id: string; label: string }

export type CloudSize = {
    id: string
    label: string
    cpus: number
    memory_mb: number
    disk_gb: number
    architecture: string
    regions: string[]
    prices: Record<string, string | null>
    price: string | null
}

export type CloudImage = { id: string; label: string; architectures: string[] }

export type CloudCatalog = {
    error: string | null
    provider: string
    regions: CloudRegion[]
    sizes: CloudSize[]
    images: CloudImage[]
    defaults: { region: string | null; size: string | null; image: string | null }
}

export type CloudPicks = { region?: string; size?: string; image?: string; environment?: string }

export type CloudStep = { key: string; name: string; desc: string; state: 'done' | 'running' | 'failed' | 'pending' }

export type CloudTask = {
    type: 'create' | 'destroy' | 'resize'
    state: 'running' | 'failed' | 'done'
    step: string
    step_label: string
    position: number
    total: number
    steps: CloudStep[]
    error: string | null
    to: string | null
}

export type CloudServer = {
    provider: string
    type: CloudProviderType
    account: string
    instance: string | null
    ip: string | null
    region: string | null
    size: string | null
    image: string | null
    monthly: string | null
    console_url: string
    task: CloudTask | null
    can: { retry: boolean; remove: boolean; detach: boolean; destroy: boolean; resize: boolean; dismiss: boolean }
}
