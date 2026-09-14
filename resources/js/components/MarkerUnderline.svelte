<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { Snippet } from 'svelte'
    import { cn } from '@/lib/utils'

    let { children, markClass }: { children?: Snippet; markClass?: string } = $props()
</script>

<span class="relative inline-block">
    {@render children?.()}
    <svg
        class={cn('pointer-events-none absolute bottom-[-0.07em] left-0 h-[0.26em] w-full', markClass)}
        viewBox="0 0 200 10"
        preserveAspectRatio="none"
        fill="none"
        aria-hidden="true"
    >
        <path
            d="M2 6 C 50 2, 120 9, 198 4"
            stroke="var(--primary)"
            stroke-width="3"
            stroke-linecap="round"
            opacity="0.85"
        />
    </svg>
</span>
