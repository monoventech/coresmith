<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { ProcessRow } from '@/lib/app-page'
    import { cn } from '@/lib/utils'

    type Uptime = {
        percent: string
        days: { date: string; state: 'full' | 'gaps' | 'down' | 'none'; title: string }[]
        label: string
        note: string
    }

    let { uptime, processes, domain }: { uptime: Uptime | null; processes: ProcessRow[]; domain: string | null } =
        $props()

    const tones = { full: 'bg-success', gaps: 'bg-warning', down: 'bg-destructive', none: 'bg-secondary' } as const

    const watched = $derived(
        processes.filter((row) => row.state !== null && !['off', 'scheduled'].includes(row.state.key))
    )
    const failed = $derived(watched.filter((row) => row.state?.key === 'failed').length)
    const restarting = $derived(watched.filter((row) => row.state?.key === 'restarting').length)
    const running = $derived(watched.filter((row) => row.state?.key === 'running').length)
    const trouble = $derived(failed + restarting > 0)
    const summary = $derived(
        watched.length === 0
            ? processes.some((row) => row.state === null)
                ? 'Waiting for the first reading'
                : null
            : trouble
              ? [
                    failed > 0 ? `${failed} failed` : null,
                    restarting > 0 ? `${restarting} ${restarting === 1 ? 'keeps' : 'keep'} restarting` : null
                ]
                    .filter((part) => part !== null)
                    .join(', ')
              : running === watched.length
                ? `${running} running`
                : `${running} of ${watched.length} running`
    )
</script>

<div
    class="flex min-w-0 flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:p-4"
    data-testid="health-card"
>
    <h3 class="type-h3 m-0 mb-1.5 max-sm:text-[15px]">Health</h3>
    {#if uptime}
        <div class="mb-3 flex flex-col gap-2" data-testid="app-uptime">
            <div class="flex flex-wrap items-baseline gap-2">
                <span class="font-display text-[26px] leading-none font-semibold">{uptime.percent}</span>
                <span class="text-[12.5px] text-muted-foreground">answering over 30 days</span>
            </div>
            <div role="img" aria-label={uptime.label} class="flex gap-0.5">
                {#each uptime.days as day (day.date)}
                    <span class="h-5.5 flex-1 rounded-xs {tones[day.state]}" title={day.title}></span>
                {/each}
            </div>
            <p class="m-0 text-[12px] text-muted-foreground">{uptime.note}</p>
        </div>
    {:else}
        <p class="m-0 mb-3 text-[12.5px] text-muted-foreground" data-testid="app-uptime-empty">
            {domain
                ? 'No uptime yet. It shows here once Coresmith has checked the app from outside.'
                : 'Uptime shows here once the app has a domain to check from outside.'}
        </p>
    {/if}
    {#if summary}
        <div class="mt-auto border-t border-border pt-1.5">
            <a
                href="#processes"
                class={cn(
                    'focus-ring -mx-2 flex min-h-10 items-center justify-between gap-3 rounded-md px-2 text-[13.5px] no-underline transition-colors duration-(--dur-fast) hover:bg-muted max-sm:min-h-11',
                    failed > 0 ? 'text-destructive-strong' : trouble ? 'text-warning-strong' : 'text-foreground'
                )}
                data-testid="health-processes"
                ><span class="text-muted-foreground">Processes</span><span>{summary}</span></a
            >
        </div>
    {/if}
</div>
