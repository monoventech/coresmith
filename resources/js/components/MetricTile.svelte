<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { cn } from '@/lib/utils'

    type Severity = 'normal' | 'warning' | 'critical'

    let {
        label,
        value,
        unit = '',
        series = [],
        severity = 'normal',
        severityLabel = ''
    }: {
        label: string
        value: string
        unit?: string
        series?: number[]
        severity?: Severity
        severityLabel?: string
    } = $props()

    const WIDTH = 120
    const HEIGHT = 28

    const tones: Record<Severity, string> = {
        normal: 'text-muted-foreground',
        warning: 'text-warning-strong',
        critical: 'text-destructive-strong'
    }

    const strokes: Record<Severity, string> = {
        normal: 'stroke-primary',
        warning: 'stroke-warning',
        critical: 'stroke-destructive'
    }

    const points = $derived.by(() => {
        if (series.length < 2) {
            return ''
        }

        const highest = Math.max(...series)
        const lowest = Math.min(...series)
        const span = highest - lowest || 1

        return series
            .map((point, index) => {
                const x = (index / (series.length - 1)) * WIDTH
                const y = HEIGHT - ((point - lowest) / span) * HEIGHT

                return `${x.toFixed(1)},${y.toFixed(1)}`
            })
            .join(' ')
    })
</script>

<div class="rounded-xl border border-border bg-card p-4">
    <p class="text-xs text-muted-foreground">{label}</p>
    <p class="mt-1 text-2xl font-semibold">
        {value}<span class="ml-0.5 text-base font-normal text-muted-foreground">{unit}</span>
    </p>

    {#if severityLabel}
        <p class={cn('mt-1 text-xs font-medium', tones[severity])}>{severityLabel}</p>
    {/if}

    {#if points}
        <svg
            viewBox="0 0 {WIDTH} {HEIGHT}"
            preserveAspectRatio="none"
            class="mt-3 h-7 w-full"
            role="presentation"
            aria-hidden="true"
        >
            <polyline
                {points}
                fill="none"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                class={strokes[severity]}
                vector-effect="non-scaling-stroke"
            />
        </svg>
    {:else}
        <p class="mt-3 h-7 text-xs text-muted-foreground">Not enough history yet</p>
    {/if}
</div>
