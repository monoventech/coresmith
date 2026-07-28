<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { cn } from '@/lib/utils'

    let { class: className }: { class?: string } = $props()
</script>

<p class={cn('text-xs font-medium tracking-[0.02em] text-muted-foreground', className)}>
    Made by Monoven
</p>
