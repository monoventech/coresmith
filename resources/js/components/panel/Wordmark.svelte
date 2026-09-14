<script lang="ts">
    import { Hammer } from '@lucide/svelte'
    import { cn } from '@/lib/utils'

    let { size = 'panel', class: className }: { size?: 'panel' | 'compact'; class?: string } = $props()
</script>

<span class={cn('inline-flex items-center', size === 'panel' ? 'gap-[9px]' : 'gap-2', className)}>
    <Hammer class={cn('text-primary', size === 'panel' ? 'size-[18px]' : 'size-4')} aria-hidden="true" />
    <span class={cn('font-display font-semibold', size === 'panel' ? 'text-[19px] tracking-[-0.01em]' : 'text-[17px]')}>Coresmith</span>
</span>
