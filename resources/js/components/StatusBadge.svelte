<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { cn } from '@/lib/utils'

    let { status, class: className }: { status: string | null; class?: string } = $props()

    type Tone = { badge: string; dot: string; motion?: 'pulse' | 'working' }

    const tones: Record<string, Tone> = {
        pending: { badge: 'bg-muted text-muted-foreground', dot: 'bg-muted-foreground' },
        deploying: { badge: 'bg-primary/10 text-primary-strong', dot: 'bg-primary', motion: 'working' },
        provisioning: { badge: 'bg-primary/10 text-primary-strong', dot: 'bg-primary', motion: 'working' },
        running: { badge: 'bg-primary/10 text-primary-strong', dot: 'bg-primary', motion: 'working' },
        active: { badge: 'bg-success/10 text-success-strong', dot: 'bg-success', motion: 'pulse' },
        succeeded: { badge: 'bg-success/10 text-success-strong', dot: 'bg-success' },
        failed: { badge: 'bg-destructive/10 text-destructive-strong', dot: 'bg-destructive' },
        cancelled: { badge: 'bg-muted text-muted-foreground', dot: 'bg-muted-foreground' },
        stopped: { badge: 'border border-border text-muted-foreground', dot: '' },
        sleeping: { badge: 'bg-muted text-muted-foreground', dot: 'bg-muted-foreground/60' },
        disconnected: { badge: 'bg-warning/10 text-warning-strong', dot: 'bg-warning' }
    }

    const key = $derived(status ?? 'pending')
    const label = $derived(key.charAt(0).toUpperCase() + key.slice(1))
    const tone = $derived(tones[key] ?? tones.pending)
</script>

<span class={cn('inline-flex w-fit items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium', tone.badge, className)}>
    {#if tone.dot}
        <span
            class={cn(
                'size-1.5 shrink-0 rounded-full',
                tone.dot,
                tone.motion === 'pulse' && 'status-dot-pulse',
                tone.motion === 'working' && 'status-dot-working'
            )}
            aria-hidden="true"
        ></span>
    {/if}
    {label}
</span>
