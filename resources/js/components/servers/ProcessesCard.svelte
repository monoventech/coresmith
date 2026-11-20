<script module lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { EntityState } from '@/lib/fleet'

    export type ProcessRow = {
        app: string
        href: string
        process: string
        state: EntityState
        memory: string | null
        restarts: number
        since: string | null
    }
</script>

<script lang="ts">
    import StateDot from '@/components/StateDot.svelte'
    import { toneText } from '@/lib/fleet'
    import { cn } from '@/lib/utils'
    import { Link } from '@inertiajs/svelte'

    let { processes }: { processes: ProcessRow[] } = $props()

    const columns = 'grid grid-cols-[1fr_1fr_0.6fr_0.8fr_1.4fr] gap-3 max-sm:grid-cols-[1fr_auto]'
</script>

<section
    class="mt-6 overflow-hidden rounded-xl border border-border bg-card"
    aria-labelledby="processes-heading"
    data-testid="processes"
>
    <div class="border-b border-border px-5.5 py-4">
        <h2 id="processes-heading" class="type-h3 m-0">Processes</h2>
        <p class="m-0 mt-1 text-[12.5px] text-muted-foreground">
            Each app's processes, read every minute. Any that failed or keep restarting come first.
        </p>
    </div>
    <div class={cn(columns, 'border-b border-border px-5.5 py-3')}>
        <span class="type-caption text-muted-foreground">Process</span>
        <span class="type-caption text-muted-foreground max-sm:hidden">App</span>
        <span class="type-caption text-muted-foreground max-sm:hidden">Memory</span>
        <span class="type-caption text-muted-foreground max-sm:hidden">Started</span>
        <span class="type-caption text-muted-foreground">State</span>
    </div>
    {#each processes as row (`${row.app}/${row.process}`)}
        <Link
            href={row.href}
            class={cn(
                columns,
                'focus-ring min-h-13 w-full items-center border-b border-border px-5.5 py-2 text-left text-[13.5px] text-foreground no-underline transition-colors duration-(--dur-fast) last:border-b-0 hover:bg-muted'
            )}
            data-testid="process-row"
        >
            <span class="min-w-0 truncate max-sm:whitespace-normal"
                ><span class="font-mono text-[13.5px] font-medium">{row.process}</span>
                <span class="whitespace-nowrap text-muted-foreground sm:hidden">· {row.app}</span></span
            >
            <span class="truncate font-mono text-[12.5px] text-muted-foreground max-sm:hidden">{row.app}</span>
            <span class="font-mono text-[12.5px] whitespace-nowrap text-muted-foreground max-sm:hidden"
                >{row.memory ?? ''}</span
            >
            <span class="text-[12.5px] whitespace-nowrap text-muted-foreground max-sm:hidden">{row.since ?? ''}</span>
            <span class={cn('inline-flex items-center gap-1.5 text-[12.5px] font-medium', toneText(row.state.tone))}>
                <StateDot tone={row.state.tone} size="sm" />{row.state.label}
            </span>
        </Link>
    {/each}
</section>
