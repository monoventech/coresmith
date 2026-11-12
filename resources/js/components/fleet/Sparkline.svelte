<script lang="ts">
    let { values }: { values: number[] } = $props()

    const points = $derived(
        values
            .map((value, index) => {
                const x = values.length === 1 ? 60 : (index / (values.length - 1)) * 120
                const y = 26 - (Math.min(100, Math.max(0, value)) / 100) * 24

                return `${x.toFixed(1)},${y.toFixed(1)}`
            })
            .join(' ')
    )
</script>

{#if values.length > 1}
    <svg
        class="block h-7 w-full text-muted-foreground"
        viewBox="0 0 120 28"
        preserveAspectRatio="none"
        aria-hidden="true"
        data-testid="sparkline"
    >
        <polyline
            {points}
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
{:else}
    <div class="h-7" aria-hidden="true"></div>
{/if}
