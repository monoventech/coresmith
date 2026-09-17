<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'

    let {
        server,
        database,
        can
    }: {
        server: { id: string; name: string; href: string }
        database: { id: string; name: string; type: string; db_user: string | null; host: string; port: number; status: string; privilege_level: string; is_external: boolean }
        can: { viewCredentials: boolean; backup: boolean; delete: boolean }
    } = $props()

    const ENGINES: Record<string, string> = { mysql: 'MySQL', postgresql: 'PostgreSQL', redis: 'Redis' }

    let deleting = $state(false)
    let working = $state(false)

    const dsn = $derived(
        database.type === 'redis' ? `redis://${database.host}:${database.port}` : `${database.type}://${database.db_user}@${database.host}:${database.port}/${database.name}`
    )

    function backup() {
        router.post(route('servers.databases.backup', [server.id, database.id]), {}, { preserveScroll: true })
    }

    function destroy() {
        working = true
        router.delete(route('servers.databases.destroy', [server.id, database.id]), { onFinish: () => ((working = false), (deleting = false)) })
    }
</script>

<AppHead title={database.name} />

<BackLink href={server.href} label={server.name} />

<div class="mb-6 flex flex-wrap items-end justify-between gap-6">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="type-h1 m-0 font-mono font-medium">{database.name}</h1>
            <Badge variant={database.status === 'active' ? 'success' : 'default'} dot>{database.status === 'active' ? 'Ready' : database.status}</Badge>
        </div>
        <p class="type-body-sm mt-1.5 text-muted-foreground">{ENGINES[database.type] ?? database.type} · on {server.name} · {database.is_external ? 'existing server' : 'localhost only'}</p>
    </div>
    {#if can.backup}
        <Button variant="outline" onclick={backup}>Back up now</Button>
    {/if}
</div>

<div class="grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-1">Connection</h3>
        <p class="mb-3 text-[12.5px] text-muted-foreground" data-testid={database.is_external ? 'external-database-note' : undefined}>
            {database.is_external
                ? `Coresmith created this database on a ${ENGINES[database.type] ?? database.type} server that was already on ${server.name}. It never reconfigures, restarts or removes that server, so check its network settings yourself.`
                : `Apps on ${server.name} reach it over localhost. It isn't exposed to the internet.`}
        </p>
        <div class="font-mono text-[12.5px] leading-[1.9] text-muted-foreground">
            <div>host {database.host}</div>
            <div>port {database.port}</div>
            {#if database.db_user}<div>user {database.db_user}</div>{/if}
        </div>
        <pre class="terminal mt-3 text-[11.5px] break-all whitespace-pre-wrap">{dsn}</pre>
        <p class="mt-2 text-[12px] text-muted-foreground">The password was shown once at creation and is encrypted at rest. Rotate it if you lose it.</p>
    </div>

    <div class="flex flex-col gap-4">
        <div class="rounded-xl border border-border bg-card px-5.5 py-5">
            <h3 class="type-h3 m-0 mb-1">Access</h3>
            <p class="mb-3 text-[12.5px] text-muted-foreground">Its own user, with only the privileges its app needs.</p>
            <div class="font-mono text-[12.5px] leading-[1.9] text-muted-foreground">
                <div>privileges {database.privilege_level}</div>
                <div>{database.is_external ? 'reachable as its server allows' : 'reachable from localhost'}</div>
            </div>
        </div>
        {#if can.delete}
            <div class="border-danger flex-1 rounded-xl border bg-card px-5.5 py-5">
                <h3 class="type-h3 m-0 mb-1">Danger zone</h3>
                <p class="mb-3 text-[12.5px] text-muted-foreground">Removes the database from the panel. Take a backup first if anything still needs the data.</p>
                <Button variant="outline" size="sm" class="text-destructive" onclick={() => (deleting = true)}>Delete this database</Button>
            </div>
        {/if}
    </div>
</div>

<Dialog.Root bind:open={deleting}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Delete {database.name}?</Dialog.Title>
            <Dialog.Description>It's removed from the panel. Take a backup first if anything still needs the data.</Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (deleting = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={destroy}>Delete it</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
