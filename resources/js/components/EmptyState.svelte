<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { Snippet } from 'svelte'
    import { cn } from '@/lib/utils'

    let {
        title,
        description = '',
        illustration,
        action,
        class: className
    }: {
        title: string
        description?: string
        illustration?: Snippet
        action?: Snippet
        class?: string
    } = $props()
</script>

<div class={cn('flex flex-col items-start gap-6 rounded-xl border border-border bg-card px-8 py-12', className)}>
    {#if illustration}
        <div class="w-32">
            {@render illustration()}
        </div>
    {/if}

    <div class="max-w-md space-y-2">
        <p class="type-h3">{title}</p>
        {#if description}
            <p class="text-sm leading-relaxed text-muted-foreground">{description}</p>
        {/if}
    </div>

    {#if action}
        {@render action()}
    {/if}
</div>
