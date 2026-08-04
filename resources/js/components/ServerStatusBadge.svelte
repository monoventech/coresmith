<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { cn } from '@/lib/utils'

    let { status, class: className }: { status: string | null; class?: string } = $props()

    const label = $derived(
        {
            pending: 'Pending',
            provisioning: 'Provisioning',
            active: 'Active',
            failed: 'Failed',
            disconnected: 'Disconnected'
        }[status ?? 'pending'] ?? 'Pending'
    )

    const tone = $derived(
        {
            pending: 'bg-muted text-muted-foreground',
            provisioning: 'bg-primary/10 text-primary',
            active: 'bg-success/10 text-success',
            failed: 'bg-destructive/10 text-destructive',
            disconnected: 'bg-warning/10 text-warning'
        }[status ?? 'pending'] ?? 'bg-muted text-muted-foreground'
    )
</script>

<span class={cn('inline-flex w-fit items-center rounded-md px-2 py-0.5 text-xs font-medium', tone, className)}>
    {label}
</span>
