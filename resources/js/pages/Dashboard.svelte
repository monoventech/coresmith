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
        <div class="grid gap-4 sm:grid-cols-3">
            {#each tiles as tile (tile.label)}
                <Link
                    href={tile.href}
                    data-testid="count-{tile.label.toLowerCase()}"
                    class="rounded-xl border border-border bg-card p-5 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                >
                    <p class="text-xs text-muted-foreground">{tile.label}</p>
                    <p class="mt-1 text-3xl font-semibold">{tile.value}</p>
                </Link>
            {/each}
        </div>

        {#if attention.length > 0}
            <section class="mt-8">
                <h2 class="mb-3 text-base font-semibold">Needs your attention</h2>
                <ul class="space-y-2" data-testid="attention">
                    {#each attention as item (item.title)}
                        <li>
                            <Link
                                href={item.href}
                                class={cn(
                                    'block rounded-xl border p-4 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                                    item.severity === 'critical'
                                        ? 'border-destructive/40 bg-destructive/5 hover:bg-destructive/10'
                                        : 'border-warning/40 bg-warning/10 hover:bg-warning/15'
                                )}
                            >
                                <p
                                    class={cn(
                                        'text-sm font-medium',
                                        item.severity === 'critical' ? 'text-destructive-strong' : 'text-warning-strong'
                                    )}
                                >
                                    {item.title}
                                </p>
                                <p class="mt-0.5 text-sm text-muted-foreground">{item.detail}</p>
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

        <section class="mt-8 rounded-xl border border-border bg-card p-6">
            <h2 class="mb-4 text-base font-semibold">Recent deployments</h2>

            {#if deployments.length === 0}
                <p class="text-sm text-muted-foreground">Nothing has been deployed yet.</p>
            {:else}
                <ul class="divide-y divide-border">
                    {#each deployments as deployment (deployment.id)}
                        <li>
                            <Link
                                href={deployment.href}
                                class="flex items-center justify-between gap-4 rounded-md py-3 outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
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
