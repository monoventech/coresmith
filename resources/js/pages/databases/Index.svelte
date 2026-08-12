<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import ServerTabs from '@/components/ServerTabs.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Heading from '@/components/Heading.svelte'
    import BlueprintRack from '@/components/illustrations/BlueprintRack.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'
    import { Button } from '@/components/ui/button'
    import { Link } from '@inertiajs/svelte'

    interface DatabaseSummary {
        id: string
        name: string
        type: string | null
        db_user: string | null
        port: number
        status: string | null
    }

    let {
        server,
        databases = [],
        can
    }: {
        server: { id: string; name: string }
        databases: DatabaseSummary[]
        can: { update: boolean }
    } = $props()

    const columnClass = 'h-12 px-4 text-left align-middle text-xs font-medium tracking-[0.01em] text-muted-foreground'
</script>

<AppHead title="Databases on {server.name}" />

<div class="px-6 py-8">
    <div class="flex items-start justify-between gap-6">
        <Heading title="Databases" description="Coresmith installs the engine on first use, bound to localhost only." />
        {#if databases.length > 0}
            <Link href="/servers/{server.id}/databases/create"><Button>New database</Button></Link>
        {/if}
    </div>

    <ServerTabs serverId={server.id} canUpdate={can.update} />

    {#if databases.length === 0}
        <EmptyState
            title="No databases yet"
            description="Create one and Coresmith installs the engine if it is missing, then makes a user with only the privileges the app needs."
        >
            {#snippet illustration()}
                <BlueprintRack class="text-muted-foreground" />
            {/snippet}
            {#snippet action()}
                <Link href="/servers/{server.id}/databases/create"><Button>Create a database</Button></Link>
            {/snippet}
        </EmptyState>
    {:else}
        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead>
                    <tr class="border-b border-border">
                        <th class={columnClass}>Name</th>
                        <th class={columnClass}>Engine</th>
                        <th class={columnClass}>User</th>
                        <th class={columnClass}>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {#each databases as database (database.id)}
                        <tr class="border-b border-border transition-colors last:border-0 hover:bg-muted/40">
                            <td class="p-4 align-middle">
                                <Link
                                    href="/servers/{server.id}/databases/{database.id}"
                                    class="rounded-sm font-mono text-xs font-medium outline-none hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                >
                                    {database.name}
                                </Link>
                            </td>
                            <td class="p-4 align-middle text-muted-foreground">{database.type} · {database.port}</td>
                            <td class="p-4 align-middle font-mono text-xs text-muted-foreground">{database.db_user}</td>
                            <td class="p-4 align-middle"><StatusBadge status={database.status} /></td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</div>
