<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: route('dashboard')
            }
        ]
    }
</script>

<script lang="ts">
    import { Link, page } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Heading from '@/components/Heading.svelte'
    import BlueprintRack from '@/components/illustrations/BlueprintRack.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'
    import { Button } from '@/components/ui/button'
    import { cn } from '@/lib/utils'

    type Attention = {
        severity: 'critical' | 'warning'
        title: string
        detail: string
        href: string
    }

    type RecentDeployment = {
        id: string
        application: string
        status: string
        commit_message: string | null
        finished_at: string | null
        href: string
    }

    let {
        counts,
        attention = [],
        attentionTotal = 0,
        deployments = [],
        can
    }: {
        hasTeam: boolean
        counts: { servers: number; applications: number; databases: number }
        attention: Attention[]
        attentionTotal: number
        deployments: RecentDeployment[]
        can: { createServer: boolean }
    } = $props()

    const user = $derived(page.props.auth.user)
    const firstName = $derived(user.name.split(' ')[0])
    const empty = $derived(counts.servers === 0)

    const tiles = $derived([
        { label: 'Servers', value: counts.servers, href: route('servers.index') },
        { label: 'Applications', value: counts.applications, href: route('applications.index') },
        { label: 'Databases', value: counts.databases, href: route('databases.index') }
    ])

    function when(iso: string | null): string {
        return iso ? new Date(iso).toLocaleString() : 'Not finished'
    }
</script>

<AppHead title="Dashboard" />

<div class="px-6 py-8">
    <Heading title="Welcome back, {firstName}" mark={firstName} description="Everything you have provisioned lives here." />

    {#if empty}
        <EmptyState
            title="No servers yet"
            description="Connect your first server and Coresmith will provision it, keep it patched, and deploy to it."
        >
            {#snippet illustration()}
                <BlueprintRack />
            {/snippet}
            {#snippet action()}
                {#if can.createServer}
                    <Button href={route('servers.create')}>Connect a server</Button>
                {:else}
                    <p class="text-sm text-muted-foreground">Ask an owner or admin of your team to connect one.</p>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <div class="grid divide-y divide-border overflow-hidden rounded-xl border border-border bg-card sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            {#each tiles as tile (tile.label)}
                <Link
                    href={tile.href}
                    data-testid="count-{tile.label.toLowerCase()}"
                    class="px-6 py-5 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                >
                    <p class="text-xs font-medium tracking-[0.02em] text-muted-foreground uppercase">{tile.label}</p>
                    <p class="mt-2 font-display text-4xl leading-none font-semibold tracking-[-0.02em]">{tile.value}</p>
                </Link>
            {/each}
        </div>

        {#if attention.length > 0}
            <section class="mt-12">
                <h2 class="mb-4 text-xl font-semibold tracking-[-0.01em]">Needs your attention</h2>
                <ul class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card" data-testid="attention">
                    {#each attention as item (item.title)}
                        <li>
                            <Link
                                href={item.href}
                                class="flex items-start gap-3 px-5 py-4 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                            >
                                <span
                                    class={cn(
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        item.severity === 'critical' ? 'bg-destructive' : 'bg-warning'
                                    )}
                                    aria-hidden="true"
                                ></span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium">{item.title}</span>
                                    <span class="mt-0.5 block text-sm text-muted-foreground">{item.detail}</span>
                                </span>
                                <span class="sr-only">{item.severity}</span>
                            </Link>
                        </li>
                    {/each}
                </ul>

                {#if attentionTotal > attention.length}
                    <p class="mt-3 text-sm text-muted-foreground">
                        and {attentionTotal - attention.length} more across your servers and applications.
                    </p>
                {/if}
            </section>
        {/if}

        <section class="mt-12">
            <h2 class="mb-4 text-xl font-semibold tracking-[-0.01em]">Recent deployments</h2>

            {#if deployments.length === 0}
                <p class="rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
                    Nothing has been deployed yet.
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
                                    <p class="truncate text-sm font-medium">{deployment.application}</p>
                                    <p class="truncate text-xs text-muted-foreground">
                                        {deployment.commit_message ?? 'No commit message'} · {when(deployment.finished_at)}
                                    </p>
                                </div>
                                <StatusBadge status={deployment.status} />
                            </Link>
                        </li>
                    {/each}
                </ul>
            {/if}
        </section>
    {/if}
</div>
