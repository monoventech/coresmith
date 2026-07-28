<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { Snippet } from 'svelte'

    let { children }: { children?: Snippet } = $props()
</script>

<span class="relative inline-block">
    {@render children?.()}
    <svg
        class="pointer-events-none absolute -bottom-1 left-0 w-full"
        height="10"
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
