<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { Snippet } from 'svelte'
    import { cn } from '@/lib/utils'

    let {
        label,
        children,
        wide = false,
        mono = false,
        title,
        class: className,
        testid
    }: {
        label: string
        children: Snippet
        wide?: boolean
        mono?: boolean
        title?: string
        class?: string
        testid?: string
    } = $props()
</script>

<div class={cn('min-w-0', wide && 'col-span-full', className)} data-testid={testid}>
    <dt class="text-[12px] text-muted-foreground">{label}</dt>
    <dd
        class={cn(
            'm-0 mt-1 wrap-anywhere',
            mono ? 'font-mono text-[13px] max-sm:text-[12.5px]' : 'text-[14px] font-medium max-sm:text-[13.5px]'
        )}
        {title}
    >
        {@render children()}
    </dd>
</div>
