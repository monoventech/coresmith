<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import ProjectScopeSelect from '@/components/projects/ProjectScopeSelect.svelte'
    import { Badge } from '@/components/ui/badge'
    import type { EntityState } from '@/lib/fleet'
    import { cn } from '@/lib/utils'

    type Row = {
        id: string
        application: string
        server: string
        release: string | null
        branch: string | null
        when: string | null
        full: string | null
        duration: string | null
        state: EntityState & { live: boolean }
        href: string | null
    }

    let {
        filter,
        filters = [],
        deployments = [],
        scope = '',
        scopes = []
    }: {
        filter: string
        filters: { key: string; label: string; count: number; href: string }[]
        deployments: Row[]
        scope: string
        scopes: { value: string; label: string }[]
    } = $props()

    const columns = 'grid grid-cols-[1fr_0.9fr_1.3fr_0.6fr_0.9fr_0.7fr_1fr] gap-3 max-[900px]:grid-cols-[1fr_auto]'
</script>

<AppHead title="Deployments" />

<div class="mb-6 flex flex-wrap items-end justify-between gap-6">
    <div>
        <h1 class="type-h1 m-0">Deployments</h1>
        <p class="type-body-sm mt-1.5 text-muted-foreground">
            Every deploy across the fleet. The running one is pinned on top.
        </p>
    </div>
</div>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <nav class="flex flex-wrap gap-2" aria-label="Filter deployments">
        {#each filters as chip (chip.key)}
            {@const selected = chip.key === filter}
            <Link
                href={chip.href}
                preserveScroll
                aria-current={selected ? 'page' : undefined}
                class={cn(
                    'focus-ring inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-[12.5px] font-medium no-underline transition-colors duration-(--dur-fast)',
                    selected ? 'chip-selected text-foreground' : 'border-border bg-transparent text-muted-foreground'
                )}
            >
                {chip.label}<span class="font-mono text-[11.5px] text-muted-foreground">{chip.count}</span>
            </Link>
        {/each}
    </nav>
    <ProjectScopeSelect
        {scopes}
        {scope}
        href={route('deployments.index')}
        keep={filter === 'all' ? {} : { status: filter }}
        class="ml-auto w-56 max-sm:ml-0 max-sm:w-full"
    />
</div>

{#if deployments.length === 0}
    <EmptyState
        title={filter === 'all' ? 'Nothing has been deployed yet.' : `No ${filter} deploys.`}
        description="Each deploy builds a new release while the current one keeps serving, then switches over when every phase passes."
    />
{:else}
    <div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="deployments-table">
        <div class={cn(columns, 'border-b border-border px-5.5 py-3')}>
            <span class="type-caption text-muted-foreground">App</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Server</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Release</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Branch</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">When</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Duration</span>
            <span class="type-caption text-muted-foreground"><span class="sr-only">Status</span></span>
        </div>
        {#each deployments as row (row.id)}
            <Link
                href={row.href ? `${row.href}?from=deployments` : '#'}
                class={cn(
                    columns,
                    'focus-ring min-h-13 w-full items-center border-b border-border px-5.5 py-2 text-left text-[13.5px] text-foreground no-underline transition-colors duration-(--dur-fast) hover:bg-muted'
                )}
                data-testid="deployment-row"
            >
                <span class="truncate font-mono text-[13.5px] font-medium">{row.application}</span>
                <span class="truncate font-mono text-[12.5px] text-muted-foreground max-[900px]:hidden"
                    >{row.server}</span
                >
                <span class="truncate font-mono text-[12.5px] text-muted-foreground max-[900px]:hidden"
                    >{row.release ?? '—'}</span
                >
                <span class="truncate font-mono text-[12.5px] text-muted-foreground max-[900px]:hidden"
                    >{row.branch ?? '—'}</span
                >
                <span class="text-muted-foreground max-[900px]:hidden" title={row.full ?? undefined}
                    >{row.when ?? '—'}</span
                >
                <span class="font-mono text-[12.5px] text-muted-foreground max-[900px]:hidden"
                    >{row.duration ?? '—'}</span
                >
                <span class="flex items-center justify-end gap-2.5">
                    <Badge
                        variant={row.state.tone}
                        dot={row.state.live || row.state.key === 'cancelling'}
                        pulse={row.state.live}>{row.state.label}</Badge
                    >
                    {#if row.state.live || row.state.key === 'cancelling'}
                        <span class="text-[12.5px] font-medium text-primary-strong">Watch</span>
                    {/if}
                </span>
            </Link>
        {/each}
        <p class="m-0 px-5.5 py-3 text-[12.5px] text-muted-foreground">
            A failed deploy never reaches visitors. The old release keeps serving until every phase passes.
        </p>
    </div>
{/if}
