<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Heading from '@/components/Heading.svelte'
    import BlueprintRack from '@/components/illustrations/BlueprintRack.svelte'
    import ServerStatusBadge from '@/components/ServerStatusBadge.svelte'
    import { Button } from '@/components/ui/button'
    import { Link } from '@inertiajs/svelte'

    interface ServerSummary {
        id: string
        name: string
        ip_address: string
        status: string | null
        os_name: string | null
        os_version: string | null
        team: { id: string; name: string | null }
    }

    let {
        servers = [],
        can = { create: false }
    }: { servers: ServerSummary[]; can?: { create: boolean } } = $props()

    const columnClass = 'h-12 px-4 text-left align-middle text-xs font-medium tracking-[0.01em] text-muted-foreground'
</script>

<AppHead title="Servers" />

<div class="px-6 py-8">
    <div class="mb-10 flex items-start justify-between gap-6">
        <Heading title="Servers" description="Every machine Coresmith manages for your teams." />

        {#if servers.length > 0 && can.create}
            <Link href="/servers/create"><Button>Add a server</Button></Link>
        {/if}
    </div>

    {#if servers.length === 0}
        <EmptyState
            title="No servers yet"
            description="Connect your first server to get started. You add Coresmith's public key to it, then provisioning takes care of the rest."
        >
            {#snippet illustration()}
                <BlueprintRack class="text-muted-foreground" />
            {/snippet}
            {#snippet action()}
                {#if can.create}
                    <Link href="/servers/create"><Button>Connect a server</Button></Link>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead>
                    <tr class="border-b border-border">
                        <th class={columnClass}>Name</th>
                        <th class={columnClass}>Address</th>
                        <th class={columnClass}>Operating system</th>
                        <th class={columnClass}>Team</th>
                        <th class={columnClass}>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {#each servers as server (server.id)}
                        <tr class="border-b border-border transition-colors last:border-0 hover:bg-muted/40">
                            <td class="p-4 align-middle">
                                <Link
                                    href="/servers/{server.id}"
                                    class="rounded-sm font-medium text-foreground outline-none hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                >
                                    {server.name}
                                </Link>
                            </td>
                            <td class="p-4 align-middle font-mono text-xs text-muted-foreground">{server.ip_address}</td>
                            <td class="p-4 align-middle text-muted-foreground">
                                {server.os_name ? `${server.os_name} ${server.os_version ?? ''}`.trim() : 'Not detected yet'}
                            </td>
                            <td class="p-4 align-middle text-muted-foreground">{server.team.name}</td>
                            <td class="p-4 align-middle"><ServerStatusBadge status={server.status} /></td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</div>
