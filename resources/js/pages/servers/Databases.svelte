<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import ServerHeaderView from '@/components/servers/ServerHeader.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { ServerHeader } from '@/lib/servers'
    import { cn } from '@/lib/utils'

    type DatabaseRow = {
        id: string
        name: string
        type: string
        db_user: string | null
        shared_redis: boolean
        host: string
        port: number
        status: string
        privilege_level: string
        is_external: boolean
        href: string
    }

    let {
        server,
        databases = [],
        can,
        shared_redis = null
    }: {
        server: ServerHeader
        databases: DatabaseRow[]
        can: { create: boolean; update: boolean }
        shared_redis?: { databases: number; can_turn_off: boolean } | null
    } = $props()

    let turningOff = $state(false)
    let working = $state(false)

    function turnOffSharedRedis() {
        working = true
        router.post(
            route('servers.shared-redis.off', server.id),
            {},
            { preserveScroll: true, onFinish: () => ((working = false), (turningOff = false)) }
        )
    }

    const ENGINES: Record<string, string> = { mysql: 'MySQL', postgresql: 'PostgreSQL', redis: 'Redis' }
    const columns = 'grid grid-cols-[1.3fr_1fr_1fr_0.8fr_0.7fr] gap-3 max-sm:grid-cols-[1fr_auto]'
    const hasExternal = $derived(databases.some((database) => database.is_external))
    const hasSharedRedis = $derived(databases.some((database) => database.shared_redis))
</script>

<AppHead title="{server.name} databases" />

<ServerHeaderView {server} />

{#if shared_redis}
    <div class="tint-warning mb-4 rounded-xl border px-5.5 py-4" data-testid="shared-redis">
        <p class="m-0 text-[13.5px]">
            {#if shared_redis.databases > 0}
                {shared_redis.databases === 1
                    ? '1 Redis database still uses'
                    : `${shared_redis.databases} Redis databases still use`} the shared Redis on port 6379, which has no password,
                so every app on <span class="whitespace-nowrap">{server.name}</span> can read and change what's in it. Give
                each one a Redis of its own from its page.
            {:else}
                No database uses the shared Redis on port 6379 any more, but it still answers without a password. Turn
                it off once no app connects to it.
            {/if}
        </p>
        {#if shared_redis.can_turn_off && can.update}
            <div class="mt-3">
                <Button variant="outline" size="sm" disabled={working} onclick={() => (turningOff = true)}
                    >Turn off the shared Redis</Button
                >
            </div>
        {/if}
    </div>
{/if}

{#if databases.length === 0}
    <EmptyState
        title="No databases on this server."
        description="Each database gets credentials of its own, and it is only reachable from this server."
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
                <span class="text-[12.5px] text-muted-foreground max-sm:hidden"
                    >{ENGINES[database.type] ?? database.type}{database.is_external ? ' · existing server' : ''}</span
                >
                <span class="truncate font-mono text-[12.5px] text-muted-foreground max-sm:hidden"
                    >{database.type !== 'redis'
                        ? database.db_user
                        : database.shared_redis
                          ? 'none, shared'
                          : 'password'}</span
                >
                <span class="font-mono text-[12.5px] text-muted-foreground max-sm:hidden">{database.port}</span>
                <span
                    class={cn(
                        'inline-flex items-center gap-1.5 text-[12.5px] font-medium',
                        database.status === 'active' ? 'text-success-strong' : 'text-muted-foreground'
                    )}
                >
                    <StateDot
                        tone={database.status === 'active' ? 'success' : 'default'}
                        size="sm"
                    />{database.status === 'active' ? 'Ready' : database.status}
                </span>
            </Link>
        {/each}
        <div class="flex flex-wrap items-center justify-between gap-3 px-5.5 py-3">
            <p class="m-0 text-[12.5px] text-muted-foreground">
                {hasExternal
                    ? "Databases Coresmith installed listen on localhost only; it doesn't control network access for the rest."
                    : 'Databases listen on localhost only.'}
                {hasSharedRedis
                    ? 'Each has credentials of its own, except Redis databases on the shared Redis, which every app on this server can reach.'
                    : 'Each one has its own credentials, never shared between apps.'}
            </p>
            {#if can.create && server.active}
                <Button size="sm" variant="outline" href={route('servers.databases.create', server.id)}
                    >Create a database</Button
                >
            {/if}
        </div>
    </div>
{/if}

<Dialog.Root bind:open={turningOff}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title
                >Turn off the shared Redis on <span class="whitespace-nowrap">{server.name}?</span></Dialog.Title
            >
            <Dialog.Description
                >Coresmith first checks that no app still connects to it, and stops only if none does. Whatever is in it
                stays on disk.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (turningOff = false)}>Keep it on</Button>
            <Button disabled={working} onclick={turnOffSharedRedis}>Turn it off</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
