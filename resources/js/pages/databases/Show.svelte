<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'
    import { Button } from '@/components/ui/button'
    import { router } from '@inertiajs/svelte'

    let {
        server,
        database,
        can
    }: {
        server: { id: string; name: string }
        database: Record<string, any>
        can: { viewCredentials: boolean; backup: boolean; delete: boolean }
    } = $props()

    const dsn = $derived(
        database.type === 'redis'
            ? `redis://${database.host}:${database.port}`
            : `${database.type}://${database.db_user}@${database.host}:${database.port}/${database.name}`
    )
</script>

<AppHead title={database.name} />

<div class="px-6 py-8">
    <div class="mb-10 flex items-start justify-between gap-6">
        <Heading title={database.name} description="{database.type} on {server.name}" />

        <div class="flex items-center gap-3">
            <StatusBadge status={database.status} />
            {#if can.backup}
                <Button variant="outline" onclick={() => router.post(`/servers/${server.id}/databases/${database.id}/backup`)}>
                    Back up now
                </Button>
            {/if}
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-border bg-card p-6">
            <h3 class="type-h3 mb-4">Connection</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Host</dt>
                    <dd class="font-mono text-xs">{database.host}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Port</dt>
                    <dd class="font-mono text-xs">{database.port}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">User</dt>
                    <dd class="font-mono text-xs">{database.db_user}</dd>
                </div>
            </dl>

            <p class="mt-4 font-mono text-xs break-all text-muted-foreground">{dsn}</p>

            <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
                The password is shown once when the database is created and is encrypted afterwards. Rotate it if you lose it.
            </p>
        </section>

        <section class="rounded-xl border border-border bg-card p-6">
            <h3 class="type-h3 mb-4">Access</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Privilege level</dt>
                    <dd>{database.privilege_level}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Reachable from</dt>
                    <dd>Localhost only</dd>
                </div>
            </dl>

            {#if can.delete}
                <div class="mt-6 border-t border-border pt-6">
                    <Button
                        variant="destructive"
                        onclick={() => router.delete(`/servers/${server.id}/databases/${database.id}`)}
                    >
                        Delete database
                    </Button>
                </div>
            {/if}
        </section>
    </div>
</div>
