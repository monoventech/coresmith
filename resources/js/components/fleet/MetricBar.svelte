<script lang="ts">
    import { cn } from '@/lib/utils'

    let {
        label,
        value,
        warnAt,
        tall = false
    }: { label: string; value: number | null; warnAt?: number; tall?: boolean } = $props()

    const warning = $derived(warnAt !== undefined && value !== null && value >= warnAt)
</script>

<div>
    <div class={cn('flex justify-between text-[11.5px] text-muted-foreground', !tall && 'mb-1.25')}>
        <span>{label}</span>
        {#if value !== null}
            <span class={cn('font-mono', tall && 'text-foreground', warning && tall && 'text-warning-strong')}
                >{value}%</span
            >
        {/if}
    </div>
    <div class={cn(tall && 'flex h-7 items-center')}>
        <div class="h-0.75 w-full rounded-xs bg-muted">
            <div
                class={cn('h-0.75 rounded-xs', warning ? 'bg-warning' : 'bg-muted-foreground')}
                style:width="{value ?? 0}%"
            ></div>
        </div>
    </div>
</div>
