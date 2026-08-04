<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { cn } from '@/lib/utils'

    let { status, class: className }: { status: string | null; class?: string } = $props()

    const tones: Record<string, string> = {
        pending: 'bg-muted text-muted-foreground',
        deploying: 'bg-primary/10 text-primary',
        provisioning: 'bg-primary/10 text-primary',
        running: 'bg-primary/10 text-primary',
        active: 'bg-success/10 text-success',
        succeeded: 'bg-success/10 text-success',
        failed: 'bg-destructive/10 text-destructive',
        cancelled: 'bg-muted text-muted-foreground',
        stopped: 'bg-warning/10 text-warning',
        sleeping: 'bg-warning/10 text-warning',
        disconnected: 'bg-warning/10 text-warning'
    }

    const key = $derived(status ?? 'pending')
    const label = $derived(key.charAt(0).toUpperCase() + key.slice(1))
    const tone = $derived(tones[key] ?? 'bg-muted text-muted-foreground')
</script>

<span class={cn('inline-flex w-fit items-center rounded-md px-2 py-0.5 text-xs font-medium', tone, className)}>{label}</span>
