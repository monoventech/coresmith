<script lang="ts">
    import type { StateTone } from '@/lib/fleet'
    import { toneFill } from '@/lib/fleet'
    import { cn } from '@/lib/utils'

    let {
        tone,
        state = '',
        size = 'md',
        class: className
    }: { tone: StateTone; state?: string; size?: 'sm' | 'md'; class?: string } = $props()

    const motion = $derived(
        ['deploying', 'provisioning', 'running'].includes(state)
            ? 'status-dot-working'
            : ['healthy', 'warning', 'live'].includes(state)
              ? 'status-dot-pulse'
              : ''
    )
</script>

<span
    class={cn('shrink-0 rounded-full', size === 'md' ? 'size-2' : 'size-1.5', toneFill(tone), motion, className)}
    aria-hidden="true"
></span>
