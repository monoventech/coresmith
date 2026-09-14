<script lang="ts">
    import { cn } from '@/lib/utils'

    let { label, value, warnAt }: { label: string; value: number | null; warnAt?: number } = $props()

    const warning = $derived(warnAt !== undefined && value !== null && value >= warnAt)
</script>

<div>
    <div class="mb-[5px] flex justify-between text-[11.5px] text-muted-foreground">
        <span>{label}</span>
        <span class="font-mono">{value === null ? '—' : `${value}%`}</span>
    </div>
    <div class="h-[3px] rounded-[2px] bg-muted">
        <div class={cn('h-[3px] rounded-[2px]', warning ? 'bg-warning' : 'bg-muted-foreground')} style:width="{value ?? 0}%"></div>
    </div>
</div>
