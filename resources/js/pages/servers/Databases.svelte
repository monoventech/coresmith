<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import ServerHeaderView from '@/components/servers/ServerHeader.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import type { ServerHeader } from '@/lib/servers'
    import { cn } from '@/lib/utils'

    type DatabaseRow = {
        id: string
        name: string
        type: string
        db_user: string | null
        host: string
        port: number
        status: string
        privilege_level: string
        href: string
    }

    let { server, databases = [], can }: { server: ServerHeader; databases: DatabaseRow[]; can: { create: boolean } } = $props()

    const ENGINES: Record<string, string> = { mysql: 'MySQL', postgresql: 'PostgreSQL', redis: 'Redis' }
    const columns = 'grid grid-cols-[1.3fr_1fr_1fr_0.8fr_0.7fr] gap-3 max-sm:grid-cols-[1fr_auto]'
</script>

<AppHead title="{server.name} databases" />

<ServerHeaderView {server} />

{#if databases.length === 0}
    <EmptyState
        title="No databases on this server."
        description="Each database gets its own user with the privileges its app needs, and it is only reachable from this server."
    >
        {#snippet action()}
            {#if can.create && server.active}
                <Button href={route('servers.databases.create', server.id)}>Create a database</Button>
            {/if}
        {/snippet}
    </EmptyState>
{:else}
    <div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="databases-table">
        <div class={cn(columns, 'border-b border-border px-5.5 py-3')}>
            <span class="type-caption text-muted-foreground">Database</span>
            <span class="type-caption text-muted-foreground max-sm:hidden">Engine</span>
            <span class="type-caption text-muted-foreground max-sm:hidden">User</span>
            <span class="type-caption text-muted-foreground max-sm:hidden">Port</span>
            <span class="type-caption text-muted-foreground">Status</span>
        </div>
        {#each databases as database (database.id)}
            <Link
                href={database.href}
                class={cn(
                    columns,
                    'focus-ring min-h-13 w-full items-center border-b border-border px-5.5 py-2 text-left text-[13.5px] text-foreground no-underline transition-colors duration-(--dur-fast) hover:bg-muted'
                )}
            >
                <span class="truncate font-mono text-[14px] font-medium">{database.name}</span>
                <span class="text-[12.5px] text-muted-foreground max-sm:hidden">{ENGINES[database.type] ?? database.type}</span>
                <span class="truncate font-mono text-[12.5px] text-muted-foreground max-sm:hidden">{database.db_user ?? '—'}</span>
                <span class="font-mono text-[12.5px] text-muted-foreground max-sm:hidden">{database.port}</span>
                <span class={cn('inline-flex items-center gap-1.5 text-[12.5px] font-medium', database.status === 'active' ? 'text-success' : 'text-muted-foreground')}>
                    <StateDot tone={database.status === 'active' ? 'success' : 'default'} size="sm" />{database.status === 'active' ? 'Ready' : database.status}
                </span>
            </Link>
        {/each}
        <div class="flex flex-wrap items-center justify-between gap-3 px-5.5 py-3">
            <p class="m-0 text-[12.5px] text-muted-foreground">Databases listen on localhost only. Each one has its own credentials, never shared between apps.</p>
            {#if can.create && server.active}
                <Button size="sm" variant="outline" href={route('servers.databases.create', server.id)}>Create a database</Button>
            {/if}
        </div>
    </div>
{/if}
