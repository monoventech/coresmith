<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { cn } from '@/lib/utils'

    let { class: className }: { class?: string } = $props()
</script>

<a
    href="https://monoven.com"
    target="_blank"
    rel="noopener noreferrer"
    class={cn(
        'focus-ring rounded-sm text-xs font-medium tracking-[0.02em] text-muted-foreground no-underline transition-colors duration-(--dur-fast) hover:text-foreground',
        className
    )}>Made by Monoven<span class="sr-only"> (opens in a new tab)</span></a
>
