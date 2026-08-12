<script lang="ts">
    import { Link, router } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import ApplicationTabs from '@/components/ApplicationTabs.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'
    import { Button } from '@/components/ui/button'

    type Row = {
        id: string
        status: string
        commit_hash: string | null
        commit_message: string | null
        started_at: string | null
        finished_at: string | null
        href: string
    }

    let {
        server,
        application,
        deployments = [],
        can
    }: {
        server: { id: string; name: string }
        application: { id: string; name: string }
        deployments: Row[]
        can: { deploy: boolean; update: boolean; manageEnvironment: boolean }
    } = $props()

    const base = $derived(`/servers/${server.id}/applications/${application.id}`)

    function when(iso: string | null): string {
        return iso ? new Date(iso).toLocaleString() : '—'
    }
</script>

<AppHead title="{application.name} deployments" />

<div class="px-6 py-8">
    <div class="flex items-start justify-between gap-6">
        <Heading title={application.name} description="Every deployment, newest first." variant="small" />
        {#if can.deploy}
            <Button onclick={() => router.post(`${base}/deploy`)}>Deploy</Button>
        {/if}
    </div>

    <ApplicationTabs serverId={server.id} applicationId={application.id} canManageEnvironment={can.manageEnvironment} canUpdate={can.update} />

    {#if deployments.length === 0}
        <p class="rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
            Nothing deployed yet.
        </p>
    {:else}
        <ul class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
            {#each deployments as deployment (deployment.id)}
                <li>
                    <Link
                        href={deployment.href}
                        class="flex items-center justify-between gap-4 px-5 py-4 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {deployment.commit_message ?? 'No commit message'}
                            </p>
                            <p class="mt-0.5 truncate font-mono text-xs text-muted-foreground">
                                {deployment.commit_hash?.slice(0, 8) ?? '—'} · {when(deployment.finished_at)}
                            </p>
                        </div>
                        <StatusBadge status={deployment.status} />
                    </Link>
                </li>
            {/each}
        </ul>
    {/if}
</div>
