<script lang="ts">
    import { Switch } from '@/components/ui/switch'
    import { cn } from '@/lib/utils'

    let {
        label,
        description,
        checked,
        busy = false,
        busyDescription,
        disabled = false,
        onchange,
        bordered = 'top',
        testid
    }: {
        label: string
        description: string
        checked: boolean
        busy?: boolean
        busyDescription?: string
        disabled?: boolean
        onchange: (on: boolean) => void
        bordered?: 'top' | 'bottom' | 'none'
        testid?: string
    } = $props()

    const id = `toggle-${Math.random().toString(36).slice(2, 9)}`
</script>

<div
    class={cn(
        'flex min-h-14 items-center gap-4 py-2 max-sm:min-h-13 max-sm:gap-3',
        bordered === 'top' && 'border-t border-border',
        bordered === 'bottom' && 'border-b border-border'
    )}
    data-testid={testid}
>
    <div class="min-w-0 flex-1">
        <div class="text-[14px] font-medium max-sm:text-[13.5px]" id="{id}-label">{label}</div>
        <div class="mt-0.5 text-[12.5px] text-muted-foreground max-sm:mt-0 max-sm:text-[11.5px]" id="{id}-description">
            {busy && busyDescription ? busyDescription : description}
        </div>
    </div>
    <span class="w-6 text-right text-[12px] font-medium text-muted-foreground max-sm:hidden" aria-hidden="true"
        >{busy ? '…' : checked ? 'On' : 'Off'}</span
    >
    <Switch
        {checked}
        {busy}
        disabled={disabled || busy}
        aria-labelledby="{id}-label"
        aria-describedby="{id}-description"
        onCheckedChange={(value) => onchange(value)}
    />
</div>
