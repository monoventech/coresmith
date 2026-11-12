<script lang="ts">
    import { cn } from '@/lib/utils'

    let { states, class: className = '' }: { states: string[]; class?: string } = $props()

    function fill(state: string): string {
        switch (state) {
            case 'done':
                return 'bg-primary'
            case 'running':
                return 'status-dot-working bg-primary'
            case 'failed':
                return 'bg-destructive'
            default:
                return 'bg-border'
        }
    }
</script>

<span class={cn('grid grid-cols-6 gap-0.75', className)} data-testid="phase-segments" aria-hidden="true">
    {#each states as state, index (index)}
        <span class={cn('block h-1 rounded-xs', fill(state))}></span>
    {/each}
</span>
