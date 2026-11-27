<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, router } from '@inertiajs/svelte'
    import { ChevronDown, Download } from '@lucide/svelte'
    import { SvelteSet } from 'svelte/reactivity'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Pager from '@/components/Pager.svelte'
    import PlanNote from '@/components/settings/PlanNote.svelte'
    import { Button } from '@/components/ui/button'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { Pages } from '@/lib/paging'
    import { cn } from '@/lib/utils'

    type Filters = { period: string; who: string; what: string }
    type Event = {
        id: number
        when: string | null
        full: string | null
        actor: { name: string; email: string | null }
        event: string
        subject: { kind: string | null; name: string; href: string | null }
        details: { key: string; value: string }[]
    }

    let {
        events = [],
        pages = null,
        filters,
        people = [],
        export: download
    }: {
        events: Event[]
        pages?: Pages | null
        filters: Filters
        people?: { value: string; label: string }[]
        export: { href: string | null; note: string | null }
    } = $props()

    const DEFAULTS: Filters = { period: '90', who: 'anyone', what: 'changes' }

    const PERIODS = [
        { value: '1', label: 'Last 24 hours' },
        { value: '7', label: 'Last 7 days' },
        { value: '30', label: 'Last 30 days' },
        { value: '90', label: 'Last 90 days' },
        { value: '365', label: 'Last year' },
        { value: 'all', label: 'Any time' }
    ]

    const KINDS = [
        { value: 'changes', label: 'Everything except SSH commands' },
        { value: 'everything', label: 'Everything, with SSH commands' },
        { value: 'servers', label: 'Servers' },
        { value: 'apps', label: 'Apps' },
        { value: 'databases', label: 'Databases' },
        { value: 'team', label: 'Team and settings' },
        { value: 'commands', label: 'SSH commands' }
    ]

    const columns =
        'grid grid-cols-[minmax(0,0.9fr)_minmax(0,1fr)_minmax(0,2.4fr)_auto] items-start gap-x-4 max-[900px]:grid-cols-[minmax(0,1fr)_auto]'

    const open = new SvelteSet<number>()
    const who = $derived([
        { value: 'anyone', label: 'Anyone' },
        { value: 'coresmith', label: 'Coresmith itself' },
        ...people
    ])
    const filtered = $derived(
        filters.period !== DEFAULTS.period || filters.who !== DEFAULTS.who || filters.what !== DEFAULTS.what
    )
    const span = $derived(PERIODS.find((period) => period.value === filters.period)?.label.toLowerCase() ?? '')

    function apply(change: Partial<Filters>) {
        const next = { ...filters, ...change }
        const query = Object.fromEntries(
            Object.entries(next).filter(([key, value]) => value !== DEFAULTS[key as keyof Filters])
        )

        open.clear()
        router.get(route('audit-log.index'), query, { preserveScroll: true, replace: true })
    }

    function toggle(id: number) {
        if (open.has(id)) {
            open.delete(id)
        } else {
            open.add(id)
        }
    }
</script>

<AppHead title="Audit log" />

<div class="mb-2 flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
    <div class="min-w-0">
        <h1 class="type-h1 m-0">Audit log</h1>
        <p class="type-body-sm m-0 mt-1.5 max-w-[80ch] text-muted-foreground">
            Every change to this team's servers, apps and settings, with who made it and when. Export CSV downloads what
            the filters show.
        </p>
    </div>
    {#if download.href}
        <Button variant="outline" href={download.href} download class="max-sm:w-full" data-testid="audit-export">
            <Download class="size-4" aria-hidden="true" />
            Export CSV
        </Button>
    {/if}
</div>

{#if download.note}
    <div class="mt-3">
        <PlanNote note={download.note} />
    </div>
{/if}

<div class="mt-5 grid grid-cols-3 gap-3 max-sm:grid-cols-2" role="group" aria-label="Filter the audit log">
    <div>
        <Label for="audit-when" class="mb-1.5">When</Label>
        <NativeSelect
            id="audit-when"
            value={filters.period}
            options={PERIODS}
            onchange={(event) => apply({ period: (event.currentTarget as HTMLSelectElement).value })}
        />
    </div>
    <div>
        <Label for="audit-who" class="mb-1.5">Who</Label>
        <NativeSelect
            id="audit-who"
            value={filters.who}
            options={who}
            onchange={(event) => apply({ who: (event.currentTarget as HTMLSelectElement).value })}
        />
    </div>
    <div class="max-sm:col-span-2">
        <Label for="audit-what" class="mb-1.5">What</Label>
        <NativeSelect
            id="audit-what"
            value={filters.what}
            options={KINDS}
            onchange={(event) => apply({ what: (event.currentTarget as HTMLSelectElement).value })}
        />
    </div>
</div>

{#if events.length === 0}
    <div class="mt-5">
        <EmptyState
            title={filtered ? 'Nothing matches these filters.' : `Nothing changed in the ${span}.`}
            description={filtered
                ? 'Try a longer period, anyone, or everything.'
                : 'Changes to servers, apps and settings show up here as they happen.'}
        >
            {#snippet action()}
                {#if filtered}
                    <Button variant="outline" onclick={() => apply(DEFAULTS)}>Clear the filters</Button>
                {:else}
                    <Button variant="outline" onclick={() => apply({ period: 'all' })}>Show any time</Button>
                {/if}
            {/snippet}
        </EmptyState>
    </div>
{:else}
    <div class="mt-5 overflow-hidden rounded-xl border border-border bg-card" data-testid="audit-events">
        <div class={cn(columns, 'border-b border-border px-5.5 py-3 max-[900px]:hidden')} aria-hidden="true">
            <span class="type-caption text-muted-foreground">When</span>
            <span class="type-caption text-muted-foreground">Who</span>
            <span class="type-caption text-muted-foreground">What</span>
            <span class="w-24"></span>
        </div>
        <ul class="m-0 list-none p-0">
            {#each events as event (event.id)}
                {@const expanded = open.has(event.id)}
                <li class="border-b border-border last:border-b-0" data-testid="audit-event">
                    <div class={cn(columns, 'px-5.5 py-3 max-sm:px-4')}>
                        <span class="pt-0.5 text-[13px] text-muted-foreground max-[900px]:hidden">
                            <time title={event.full ?? undefined}>{event.when}</time>
                        </span>
                        <span class="min-w-0 truncate pt-0.5 text-[13px] max-[900px]:hidden">{event.actor.name}</span>
                        <span class="block min-w-0">
                            <span class="block text-[13.5px] font-medium" data-testid="audit-event-name"
                                >{event.event}</span
                            >
                            {#if event.subject.name || event.subject.kind}
                                <span
                                    class="mt-0.5 flex min-w-0 items-baseline gap-1.5 text-[12px] text-muted-foreground"
                                >
                                    {#if event.subject.kind}<span class="shrink-0">{event.subject.kind}</span>{/if}
                                    {#if event.subject.kind && event.subject.name}<span aria-hidden="true">·</span>{/if}
                                    {#if event.subject.name}
                                        {#if event.subject.href}
                                            <Link
                                                href={event.subject.href}
                                                class="focus-ring min-w-0 truncate rounded-sm font-mono text-foreground underline-offset-4 hover:underline"
                                                >{event.subject.name}</Link
                                            >
                                        {:else}
                                            <span class="min-w-0 truncate font-mono">{event.subject.name}</span>
                                        {/if}
                                    {/if}
                                </span>
                            {/if}
                            <span class="mt-0.5 hidden text-[12px] text-muted-foreground max-[900px]:block"
                                >{event.actor.name} · {event.when}</span
                            >
                        </span>
                        <button
                            type="button"
                            class="focus-ring inline-flex min-h-9 w-24 shrink-0 cursor-pointer items-center justify-end gap-1 rounded-md border-0 bg-transparent px-2 text-[12.5px] font-medium text-muted-foreground hover:text-foreground max-sm:min-h-11 max-sm:w-auto"
                            aria-expanded={expanded}
                            aria-controls="audit-details-{event.id}"
                            onclick={() => toggle(event.id)}
                            data-testid="audit-details-toggle"
                        >
                            Details
                            <ChevronDown
                                class={cn(
                                    'size-4 transition-transform duration-(--dur-fast) motion-reduce:transition-none',
                                    expanded && 'rotate-180'
                                )}
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                    {#if expanded}
                        <dl
                            id="audit-details-{event.id}"
                            class="m-0 grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-x-4 gap-y-1.5 border-t border-border bg-muted/40 px-5.5 py-3 text-[12.5px] max-sm:grid-cols-1 max-sm:gap-y-0.5 max-sm:px-4"
                            data-testid="audit-details"
                        >
                            <dt class="text-muted-foreground">At</dt>
                            <dd class="m-0 max-sm:mb-1.5">{event.full}</dd>
                            <dt class="text-muted-foreground">By</dt>
                            <dd class="m-0 max-sm:mb-1.5">
                                {event.actor.name}{#if event.actor.email}&nbsp;<span class="text-muted-foreground"
                                        >({event.actor.email})</span
                                    >{/if}
                            </dd>
                            {#each event.details as detail, index (`${index}:${detail.key}`)}
                                <dt class="text-muted-foreground">{detail.key}</dt>
                                <dd class="m-0 font-mono text-[12px] wrap-anywhere max-sm:mb-1.5">
                                    {detail.value === '' ? 'none' : detail.value}
                                </dd>
                            {/each}
                        </dl>
                    {/if}
                </li>
            {/each}
        </ul>
        {#if pages}
            <Pager
                {pages}
                label="Audit log pages"
                noun="events"
                class="border-t border-border px-5.5 py-3 max-sm:px-4"
            />
        {/if}
    </div>
{/if}
