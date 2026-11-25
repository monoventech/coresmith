<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import CardFact from '@/components/CardFact.svelte'
    import CardFacts from '@/components/CardFacts.svelte'
    import SectionCard from '@/components/SectionCard.svelte'
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

<SectionCard title="Health" testid="health-card">
    {#snippet description()}
        {#if uptime}
            <p class="m-0">{uptime.note}</p>
        {:else}
            <p class="m-0" data-testid="app-uptime-empty">
                {domain
                    ? 'No uptime yet. It shows here once Coresmith has checked the app from outside.'
                    : 'Uptime shows here once the app has a domain to check from outside.'}
            </p>
        {/if}
    {/snippet}
    {#if uptime || summary}
        <CardFacts>
            {#if uptime}
                <CardFact label="Uptime, 30 days" class="max-sm:col-span-full" testid="app-uptime">
                    <span class="block">{uptime.percent}</span>
                    <span role="img" aria-label={uptime.label} class="mt-2 flex h-4 gap-0.5">
                        {#each uptime.days as day (day.date)}
                            <span class="flex-1 rounded-xs {tones[day.state]}" title={day.title}></span>
                        {/each}
                    </span>
                </CardFact>
            {/if}
            {#if summary}
                <CardFact label="Processes" class="max-sm:col-span-full">
                    <a
                        href="#processes"
                        class={cn(
                            'focus-ring relative inline-block rounded-sm underline-offset-4 hover:underline pointer-coarse:after:absolute pointer-coarse:after:inset-x-0 pointer-coarse:after:-inset-y-3',
                            failed > 0 ? 'text-destructive-strong' : trouble ? 'text-warning-strong' : 'text-foreground'
                        )}
                        data-testid="health-processes">{summary}</a
                    >
                </CardFact>
            {/if}
        </CardFacts>
    {/if}
</SectionCard>
