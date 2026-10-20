import type { EntityState } from '@/lib/fleet'

export type MetricSeries = { now: number | null; series: number[] }

export type ProvisioningPhase = { name: string; desc: string; state: 'done' | 'running' | 'failed' | 'pending' }

export type AuditFinding = {
    key: string
    severity: 'blocker' | 'warning' | 'info'
    title: string
    detail: string
    fix: string | null
}

export type ServerAudit = {
    state: 'running' | 'done' | 'failed'
    ran: string | null
    error: string | null
    findings: AuditFinding[]
    blockers: number
    warnings: number
    fresh: boolean
    can_proceed: boolean
}

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
        error: string | null
        failed_phase: number | null
        log: string | null
        public_key: string
        ssh: string | null
        ssh_user: string
        audit: ServerAudit | null
    } | null
    tabs: { apps: string; databases: string; hardening: string; activity: string }
    can: { update: boolean; delete: boolean; manageFirewall: boolean; createApplication: boolean }
}
