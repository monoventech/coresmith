<script lang="ts">
    let { values = [], stroke = 'currentColor' }: { values: number[]; stroke?: string } = $props()

    const WIDTH = 220
    const HEIGHT = 44

    const path = $derived.by(() => {
        if (values.length === 0) {
            return ''
        }

        const points = values.length === 1 ? [values[0], values[0]] : values
        const step = WIDTH / (points.length - 1)

        return points
            .map(
                (value, index) =>
                    `${index ? 'L' : 'M'}${(index * step).toFixed(1)} ${(HEIGHT - (Math.max(0, Math.min(100, value)) / 100) * HEIGHT).toFixed(1)}`
            )
            .join(' ')
    })
</script>

<svg
    width="100%"
    height={HEIGHT}
    viewBox="0 0 {WIDTH} {HEIGHT}"
    preserveAspectRatio="none"
    class="block text-muted-foreground"
    aria-hidden="true"
>
    {#if path}
        <path d={path} fill="none" {stroke} stroke-width="1.5" vector-effect="non-scaling-stroke" />
    {/if}
</svg>
