<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { Snippet } from 'svelte'
    import { cn } from '@/lib/utils'

    let { children, class: className, testid }: { children: Snippet; class?: string; testid?: string } = $props()
</script>

<dl
    class={cn(
        'm-0 grid grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-x-6 gap-y-4 border-t border-border px-5.5 py-4 max-sm:grid-cols-2 max-sm:gap-x-4 max-sm:px-4 max-sm:py-3.5',
        className
    )}
    data-testid={testid}
>
    {@render children()}
</dl>
