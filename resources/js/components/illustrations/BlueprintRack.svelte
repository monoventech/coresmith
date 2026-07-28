<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { cn } from '@/lib/utils'

    let { class: className }: { class?: string } = $props()
</script>

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 120 96"
    fill="none"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    class={cn('text-muted-foreground', className)}
>
    <path d="M22.5 12.5h75c1.4 0 2.2.9 2.1 2.3v66c0 1.4-.8 2.2-2.2 2.2h-75c-1.4 0-2.1-.8-2.1-2.2v-66c0-1.4.8-2.3 2.2-2.3Z" />
    <path d="M28.5 22.5h63.2M28.8 38.2h63" />
    <path d="M28.6 54h63.3M28.5 69.8h63.2" />
    <path d="M35 30.4h1.5M35 46.2h1.5M35 62h1.5M35 77.8h1.5" opacity="0.9" />
    <path d="M30 90.5v4M90 90.5v4" />
    <path d="M78 30.4h6M78 46.2h6M78 62h6M78 77.8h6" opacity="0.5" />
    <path d="M45.5 30.4h1.5" stroke="var(--primary)" />
</svg>
