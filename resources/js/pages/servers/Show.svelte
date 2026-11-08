<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import ProvisioningPanel from '@/components/servers/ProvisioningPanel.svelte'
    import ServerHeaderView from '@/components/servers/ServerHeader.svelte'
    import ServerMovePanel from '@/components/servers/ServerMovePanel.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import type { EntityState } from '@/lib/fleet'
    import { toneText } from '@/lib/fleet'
    import type { ServerHeader } from '@/lib/servers'
    import { cn } from '@/lib/utils'

    type AppRow = {
        id: string
        name: string
        kind: string
        runtime: string
        user: string
        ports: string
        state: EntityState
        href: string
    }

    let { server, applications = [] }: { server: ServerHeader; applications: AppRow[] } = $props()

    const WORDS = ['no', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten']

    const stacks = $derived(new Set(applications.map((application) => application.kind)).size)
    const stackText = $derived(`${WORDS[stacks] ?? stacks} ${stacks === 1 ? 'stack' : 'stacks'}`)
    const columns = 'grid grid-cols-[1.3fr_1fr_1fr_1fr_0.7fr] gap-3 max-sm:grid-cols-[1fr_auto]'
</script>

<AppHead title={server.name} />

<ServerHeaderView {server} />

{#if server.move}
    <ServerMovePanel move={server.move} server={server.id} />
{/if}

{#if server.provisioning}
    <ProvisioningPanel {server} />
{:else if applications.length === 0}
    <EmptyState
        title="Nothing deployed here yet."
        description="Point Coresmith at a repository and it builds, releases and serves it on this server, as its own Linux user."
    >
        {#snippet action()}
            {#if server.can.createApplication}
                <Button href={route('servers.applications.create', server.id)}>Add an app</Button>
            {/if}
        {/snippet}
    </EmptyState>
{:else}
    <div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="applications-table">
        <div class={cn(columns, 'border-b border-border px-5.5 py-3')}>
            <span class="type-caption text-muted-foreground">Application</span>
            <span class="type-caption text-muted-foreground max-sm:hidden">Runtime</span>
            <span class="type-caption text-muted-foreground max-sm:hidden">Linux user</span>
            <span class="type-caption text-muted-foreground max-sm:hidden">Port block</span>
            <span class="type-caption text-muted-foreground">Status</span>
        </div>
        {#each applications as application (application.id)}
            <Link
                href={application.href}
                class={cn(
                    columns,
                    'focus-ring min-h-13 w-full items-center border-b border-border px-5.5 py-2 text-left text-[13.5px] text-foreground no-underline transition-colors duration-(--dur-fast) hover:bg-muted'
                )}
            >
                <span class="min-w-0 truncate"
                    ><span class="font-mono text-[14px] font-medium">{application.name}</span><span
                        class="text-muted-foreground">{` · ${application.kind}`}</span
                    ></span
                >
                <span class="font-mono text-[12.5px] text-muted-foreground max-sm:hidden">{application.runtime}</span>
                <span class="truncate font-mono text-[12.5px] text-muted-foreground max-sm:hidden"
                    >{application.user}</span
                >
                <span class="font-mono text-[12.5px] text-muted-foreground max-sm:hidden">{application.ports}</span>
                <span
                    class={cn(
                        'inline-flex items-center gap-1.5 text-[12.5px] font-medium',
                        toneText(application.state.tone)
                    )}
                >
                    <StateDot tone={application.state.tone} size="sm" />{application.state.label}
                </span>
            </Link>
        {/each}
        <div class="flex flex-wrap items-center justify-between gap-3 px-5.5 py-3">
            <p class="m-0 text-[12.5px] text-muted-foreground">
                Each app runs as its own Linux user with its own ports, env file and database credentials, {stackText},
                one server, full isolation.
            </p>
            {#if server.can.createApplication}
                <Button size="sm" variant="outline" href={route('servers.applications.create', server.id)}
                    >Add an app</Button
                >
            {/if}
        </div>
    </div>
{/if}
