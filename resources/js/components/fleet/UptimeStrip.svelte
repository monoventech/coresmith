<script lang="ts">
    import { cn } from '@/lib/utils'

    let { days }: { days: (number | null)[] } = $props()

    function fill(day: number | null): string {
        if (day === null) {
            return 'bg-muted'
        }

        if (day >= 99.5) {
            return 'bg-success/75'
        }

        return day >= 95 ? 'bg-warning' : 'bg-destructive'
    }
</script>

<span
    class="mt-1.5 grid h-3.5 gap-0.5"
    style:grid-template-columns="repeat({days.length}, minmax(0, 1fr))"
    aria-hidden="true"
    data-testid="uptime-strip"
>
    {#each days as day, index (index)}
        <span class={cn('block rounded-xs', fill(day))}></span>
    {/each}
</span>
