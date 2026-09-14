import type { EntityState } from '@/lib/fleet'

export type MetricSeries = { now: number | null; series: number[] }

export type ProvisioningPhase = { name: string; desc: string; state: 'done' | 'running' | 'failed' | 'pending' }

export type ServerHeader = {
    id: string
    name: string
    environment: string | null
    region: string | null
    state: EntityState
    meta: string
    active: boolean
    metrics: { cpu: MetricSeries; memory: MetricSeries; disk: MetricSeries }
    disk_warning: { percent: number; apps: number } | null
    host_key: { expected: string | null; observed: string; changed_at: string | null } | null
    provisioning: {
        status: string | null
        completed: number
        phases: ProvisioningPhase[]
        log: string | null
        public_key: string
        ssh: string
        ssh_user: string
    } | null
    tabs: { apps: string; databases: string; hardening: string; activity: string }
    can: { update: boolean; delete: boolean; manageFirewall: boolean; createApplication: boolean }
}
