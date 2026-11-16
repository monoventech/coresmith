<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { cn } from '@/lib/utils'

    let {
        tags = [],
        limit = Number.POSITIVE_INFINITY,
        class: className,
        chipClass
    }: { tags?: string[]; limit?: number; class?: string; chipClass?: string } = $props()

    const shown = $derived(tags.slice(0, limit))
    const more = $derived(tags.slice(limit))
</script>

{#if tags.length > 0}
    <span class={cn('inline-flex min-w-0 flex-wrap items-center gap-1', className)} data-testid="app-tags">
        {#each shown as tag (tag)}
            <span
                class={cn(
                    'inline-block max-w-32 truncate rounded-sm border border-border bg-muted px-1.5 align-middle text-[11px] leading-4.5 font-medium text-muted-foreground',
                    chipClass
                )}
                title={tag}>{tag}</span
            >
        {/each}
        {#if more.length > 0}
            <span class="text-[11px] font-medium text-muted-foreground" title={more.join(', ')}>+{more.length}</span>
        {/if}
    </span>
{/if}
