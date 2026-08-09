<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Databases', href: route('databases.index') }]
    }
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Heading from '@/components/Heading.svelte'

    type Row = {
        id: string
        name: string
        type: string | null
        db_user: string | null
        server: { id: string; name: string | null }
        href: string
    }

    let { databases = [] }: { databases: Row[] } = $props()
</script>

<AppHead title="Databases" />

<div class="px-6 py-8">
    <Heading title="Databases" description="Every managed database, across all your servers." />

    {#if databases.length === 0}
        <EmptyState title="No databases yet" description="Open a server and create one to get started." />
    {:else}
        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium">Engine</th>
                        <th scope="col" class="px-4 py-3 font-medium">User</th>
                        <th scope="col" class="px-4 py-3 font-medium">Server</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    {#each databases as database (database.id)}
                        <tr class="hover:bg-muted/40">
                            <td class="px-4 py-3">
                                <Link
                                    href={database.href}
                                    class="font-medium outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    {database.name}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{database.type ?? '—'}</td>
                            <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{database.db_user ?? '—'}</td>
                            <td class="px-4 py-3 text-muted-foreground">{database.server.name ?? '—'}</td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</div>
