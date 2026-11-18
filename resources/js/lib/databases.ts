import type { StateTone } from '@/lib/fleet'

export type BackupRow = { stamp: string; when: string; size: string; on_server: boolean; in_s3: boolean }

export type BackupList = { rows: BackupRow[]; server_error: string | null; s3_error: string | null }

export type RestoreState = {
    state: 'running' | 'done' | 'failed'
    when: string
    step: string | null
    error: string | null
}

export type RestoreApp = { id: string; name: string; uses: boolean; running: boolean }

export function restoreStep(step: string | null, database: string): string {
    switch (step) {
        case 'backing_up':
            return `Backing up ${database} as it is now.`
        case 'copying':
            return 'Getting the backup ready.'
        case 'stopping':
            return 'Stopping apps.'
        case 'restoring':
            return `Replacing what's in ${database}.`
        case 'starting':
            return 'Starting apps again.'
        default:
            return 'Waiting to start.'
    }
}

export type ExpectedDatabase = {
    key: string
    lead: string
    detail: string
    field: 'database' | 'redis'
    create: { value: string; label: string } | null
    existing: { value: string; label: string }[]
}

export function databaseState(status: string): { key: string; label: string; tone: StateTone } {
    switch (status) {
        case 'active':
            return { key: 'ready', label: 'Ready', tone: 'success' }
        case 'creating':
            return { key: 'creating', label: 'Creating', tone: 'accent' }
        case 'failed':
            return { key: 'failed', label: 'Not created', tone: 'destructive' }
        default:
            return { key: status, label: status, tone: 'default' }
    }
}
