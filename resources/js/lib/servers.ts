import type { CloudServer } from '@/lib/cloud'
import type { EntityState } from '@/lib/fleet'
import type { ServerMove } from '@/lib/moves'

export type MetricSeries = { now: number | null; series: number[]; detail: string | null }

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
    this_machine: boolean
    metrics: { cpu: MetricSeries; memory: MetricSeries; disk: MetricSeries }
    unreachable: { last_answer: string | null; reason: string; can_reboot: boolean } | null
    disk_warning: { percent: number; apps: number } | null
    host_key: { expected: string | null; observed: string; changed_at: string | null } | null
    move: ServerMove | null
    provisioning: {
        status: string | null
        completed: number
        phases: ProvisioningPhase[]
        error: string | null
        failed_phase: number | null
        log: string | null
        public_key: string
        connect_command: string
        connect_error: string | null
        connected: boolean
        password_sign_in: boolean
        ssh: string | null
        ssh_user: string
        audit: ServerAudit | null
    } | null
    cloud: CloudServer | null
    tabs: { apps: string; databases: string; hardening: string; activity: string }
    can: { update: boolean; delete: boolean; manageFirewall: boolean; createApplication: boolean }
}
