<script lang="ts">
    import { Check, Circle, CircleOff, Loader, X } from '@lucide/svelte'

    let { state }: { state: string } = $props()
</script>

<span class="inline-flex shrink-0" class:phase-spin={state === 'running'} data-state={state}>
    {#if state === 'done'}
        <Check class="size-4 text-success" aria-label="Done" />
    {:else if state === 'running'}
        <Loader class="size-4 text-primary" aria-label="Running" />
    {:else if state === 'failed'}
        <X class="size-4 text-destructive" aria-label="Failed" />
    {:else if state === 'skipped'}
        <CircleOff class="size-4 text-muted-foreground" aria-label="Skipped" />
    {:else}
        <Circle class="size-4 text-muted-foreground" aria-label="Pending" />
    {/if}
</span>
