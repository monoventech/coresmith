<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Applications', href: route('applications.index') }]
    }
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Heading from '@/components/Heading.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'

    type Row = {
        id: string
        name: string
        domain: string | null
        type: string | null
        framework: string | null
        status: string | null
        power_state: string | null
        server: { id: string; name: string | null }
        href: string
    }

    let { applications = [] }: { applications: Row[] } = $props()
</script>

<AppHead title="Applications" />

<div class="px-6 py-8">
    <Heading title="Applications" description="Every application Coresmith deploys, across all your servers." />

    {#if applications.length === 0}
        <EmptyState
            title="No applications yet"
            description="Open a server and add an application to deploy your first site."
        />
    {:else}
        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium">Domain</th>
                        <th scope="col" class="px-4 py-3 font-medium">Server</th>
                        <th scope="col" class="px-4 py-3 font-medium">Stack</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    {#each applications as application (application.id)}
                        <tr class="hover:bg-muted/40">
                            <td class="px-4 py-3">
                                <Link
                                    href={application.href}
                                    class="font-medium outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    {application.name}
                                </Link>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-muted-foreground">
                                {application.domain ?? 'No domain'}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{application.server.name ?? '—'}</td>
                            <td class="px-4 py-3 text-muted-foreground">{application.framework ?? application.type ?? '—'}</td>
                            <td class="px-4 py-3"><StatusBadge status={application.power_state ?? application.status} /></td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</div>
