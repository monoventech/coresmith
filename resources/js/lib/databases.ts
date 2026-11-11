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
