<script lang="ts">
    import Sparkline from '@/components/fleet/Sparkline.svelte'

    let { label, value, trend }: { label: string; value: number | null; trend: number[] } = $props()
</script>

<div>
    <div class="flex justify-between text-[11.5px] text-muted-foreground">
        <span>{label}</span>
        {#if value !== null}
            <span class="font-mono text-foreground">{value}%</span>
        {/if}
    </div>
    <Sparkline values={trend} />
</div>
