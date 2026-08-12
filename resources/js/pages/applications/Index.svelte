<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import ServerTabs from '@/components/ServerTabs.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Heading from '@/components/Heading.svelte'
    import BlueprintRack from '@/components/illustrations/BlueprintRack.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'
    import { Button } from '@/components/ui/button'
    import { Link } from '@inertiajs/svelte'

    interface AppSummary {
        id: string
        name: string
        slug: string
        type: string | null
        framework: string | null
        domain: string | null
        status: string | null
    }

    let {
        server,
        applications = [],
        can
    }: {
        server: { id: string; name: string }
        applications: AppSummary[]
        can: { update: boolean }
    } = $props()

    const columnClass = 'h-12 px-4 text-left align-middle text-xs font-medium tracking-[0.01em] text-muted-foreground'
</script>

<AppHead title="Applications on {server.name}" />

<div class="px-6 py-8">
    <div class="flex items-start justify-between gap-6">
        <Heading title="Applications" description="Everything deployed on {server.name}." />
        {#if applications.length > 0}
            <Link href="/servers/{server.id}/applications/create"><Button>New application</Button></Link>
        {/if}
    </div>

    <ServerTabs serverId={server.id} canUpdate={can.update} />

    {#if applications.length === 0}
        <EmptyState
            title="Nothing deployed here yet"
            description="Point Coresmith at a repository and it will build, release and serve it on this server."
        >
            {#snippet illustration()}
                <BlueprintRack class="text-muted-foreground" />
            {/snippet}
            {#snippet action()}
                <Link href="/servers/{server.id}/applications/create"><Button>Create an application</Button></Link>
            {/snippet}
        </EmptyState>
    {:else}
        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full caption-bottom text-sm">
                <thead>
                    <tr class="border-b border-border">
                        <th class={columnClass}>Name</th>
                        <th class={columnClass}>Domain</th>
                        <th class={columnClass}>Stack</th>
                        <th class={columnClass}>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {#each applications as application (application.id)}
                        <tr class="border-b border-border transition-colors last:border-0 hover:bg-muted/40">
                            <td class="p-4 align-middle">
                                <Link
                                    href="/servers/{server.id}/applications/{application.id}"
                                    class="rounded-sm font-medium outline-none hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                >
                                    {application.name}
                                </Link>
                                <p class="font-mono text-xs text-muted-foreground">{application.slug}</p>
                            </td>
                            <td class="p-4 align-middle text-muted-foreground">{application.domain ?? 'Not assigned'}</td>
                            <td class="p-4 align-middle text-muted-foreground">{application.framework ?? application.type}</td>
                            <td class="p-4 align-middle"><StatusBadge status={application.status} /></td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</div>
