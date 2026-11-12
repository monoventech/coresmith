<script lang="ts">
    import { cn } from '@/lib/utils'

    let { values }: { values: number[] } = $props()

    const most = $derived(Math.max(1, ...values))
</script>

<span class="flex h-7 items-end gap-0.75" aria-hidden="true" data-testid="day-bars">
    {#each values as value, index (index)}
        <span
            class={cn('block w-1.25 rounded-xs', index === values.length - 1 ? 'bg-primary' : 'bg-foreground/30')}
            style:height="{4 + (value / most) * 24}px"
        ></span>
    {/each}
</span>
