<script lang="ts">
    import { Button } from '@/components/ui/button'
    import { restoreStep } from '@/lib/databases'
    import type { BackupList, BackupRow, RestoreState } from '@/lib/databases'
    import { cn } from '@/lib/utils'

    let {
        serverName,
        databaseName,
        backups,
        restore,
        s3,
        canRestore,
        canBackup,
        gaveUp,
        onRestore,
        onBackup
    }: {
        serverName: string
        databaseName: string
        backups: BackupList | null | undefined
        restore: RestoreState | null
        s3: boolean
        canRestore: boolean
        canBackup: boolean
        gaveUp: boolean
        onRestore: (row: BackupRow) => void
        onBackup: () => void
    } = $props()

    const running = $derived(restore?.state === 'running')

    function where(row: BackupRow): string {
        if (row.on_server && row.in_s3) {
            return `on ${serverName} and in S3`
        }

        return row.on_server ? `on ${serverName}` : 'in S3'
    }
</script>

<div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="backups-card">
    <h3 class="type-h3 m-0 mb-1">Backups</h3>
    <p class="mb-3 text-[12.5px] text-muted-foreground">
        Dumps on {serverName}{s3 ? ', and copies in S3' : ''}. Restoring replaces everything in {databaseName}.
    </p>

    {#if restore}
        <div
            class={cn(
                'mb-3 rounded-lg border px-3.5 py-2.5 text-[12.5px] leading-normal',
                restore.state === 'failed'
                    ? 'tint-destructive'
                    : restore.state === 'done'
                      ? 'tint-success'
                      : 'tint-primary'
            )}
            role="status"
            data-testid="restore-status"
        >
            {#if restore.state === 'running'}
                <p class="m-0 font-medium">Restoring {databaseName} from {restore.when}…</p>
                <p class="m-0 mt-0.5">{restoreStep(restore.step, databaseName)}</p>
            {:else if restore.state === 'done'}
                <p class="m-0">Restored {databaseName} from {restore.when}.</p>
            {:else}
                <p class="m-0">{restore.error}</p>
            {/if}
        </div>
    {/if}

    {#if !backups}
        <p class="m-0 text-[12.5px] text-muted-foreground" data-testid="backups-loading">
            {gaveUp ? "Coresmith couldn't list the backups yet. Reload the page to try again." : 'Listing backups…'}
        </p>
    {:else}
        {#if backups.server_error}
            <p class="m-0 mb-2 text-[12.5px] text-muted-foreground" data-testid="backups-server-error">
                {backups.server_error}
            </p>
        {/if}
        {#if backups.s3_error}
            <p
                class="tint-warning m-0 mb-2 rounded-lg border px-3.5 py-2.5 text-[12.5px]"
                data-testid="backups-s3-error"
            >
                {backups.s3_error}
            </p>
        {/if}

        {#if backups.rows.length > 0}
            <div class="flex max-h-80 flex-col overflow-y-auto" data-testid="backups-list">
                {#each backups.rows as row (row.stamp)}
                    <div class="flex min-h-11 items-center gap-3 border-t border-border" data-testid="backup-row">
                        <span class="min-w-0 flex-1 font-mono text-[12.5px] text-muted-foreground">
                            <span class="text-foreground">{row.when}</span> · {row.size} · {where(row)}
                        </span>
                        {#if canRestore}
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={running}
                                aria-label="Restore the backup from {row.when}"
                                onclick={() => onRestore(row)}>Restore</Button
                            >
                        {/if}
                    </div>
                {/each}
            </div>
        {:else if !backups.server_error}
            <p class="m-0 text-[12.5px] text-muted-foreground" data-testid="backups-empty">
                No backups yet. Back up now to make the first one.
            </p>
            {#if canBackup}
                <div class="mt-3">
                    <Button variant="outline" size="sm" disabled={running} onclick={onBackup}>Back up now</Button>
                </div>
            {/if}
        {/if}
    {/if}
</div>
