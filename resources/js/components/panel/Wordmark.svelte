<script lang="ts">
    import { cn } from '@/lib/utils'
    import { Hammer } from '@lucide/svelte'

    let { size = 'panel', class: className }: { size?: 'panel' | 'compact'; class?: string } = $props()
</script>

<span class={cn('inline-flex items-center', size === 'panel' ? 'gap-2.25' : 'gap-2', className)}>
    <Hammer class={cn('text-primary', size === 'panel' ? 'size-4.5' : 'size-4')} aria-hidden="true" />
    <span class={cn('font-display font-semibold', size === 'panel' ? 'text-[19px] tracking-[-0.01em]' : 'text-[17px]')}
        >Coresmith</span
    >
</span>
