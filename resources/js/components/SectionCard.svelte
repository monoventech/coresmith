<script lang="ts" module>
    export const bandX = 'px-5.5 max-sm:px-4'
</script>

<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { Snippet } from 'svelte'
    import { cn } from '@/lib/utils'

    let {
        title,
        titleId,
        level = 3,
        description,
        badge,
        actions,
        children,
        footer,
        danger = false,
        class: className,
        testid
    }: {
        title: string
        titleId?: string
        level?: 2 | 3
        description?: string | Snippet
        badge?: Snippet
        actions?: Snippet
        children?: Snippet
        footer?: Snippet
        danger?: boolean
        class?: string
        testid?: string
    } = $props()
</script>

<div
    class={cn(
        'min-w-0 overflow-hidden rounded-xl border bg-card',
        danger ? 'border-danger' : 'border-border',
        className
    )}
    data-testid={testid}
>
    <div
        class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3 px-5.5 pt-5 pb-4 last:pb-5 max-sm:px-4 max-sm:pt-4 max-sm:pb-3.5 max-sm:last:pb-4"
    >
        <div class="min-w-0 flex-1 basis-80">
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <svelte:element this={level === 2 ? 'h2' : 'h3'} id={titleId} class="type-h3 m-0 max-sm:text-[16px]"
                    >{title}</svelte:element
                >
                {@render badge?.()}
            </div>
            {#if typeof description === 'string'}
                <p class="m-0 mt-1 max-w-[100ch] text-[12.5px] text-pretty text-muted-foreground">{description}</p>
            {:else if description}
                <div class="mt-1 max-w-[100ch] text-[12.5px] text-pretty text-muted-foreground">
                    {@render description()}
                </div>
            {/if}
        </div>
        {#if actions}
            <div class="flex flex-wrap items-center gap-2 max-sm:w-full max-sm:*:min-h-11 max-sm:*:flex-1">
                {@render actions()}
            </div>
        {/if}
    </div>
    {@render children?.()}
    {#if footer}
        <div
            class="flex flex-col gap-1 border-t border-border px-5.5 py-3 text-[12px] text-muted-foreground max-sm:px-4"
        >
            {@render footer()}
        </div>
    {/if}
</div>
