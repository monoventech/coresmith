<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, page } from '@inertiajs/svelte'
    import { cn } from '@/lib/utils'

    type Tab = { label: string; href: string; current?: boolean }

    let { tabs = [], label = 'Sections', class: className }: { tabs: Tab[]; label?: string; class?: string } = $props()

    const path = $derived(new URL(page.url, 'http://localhost').pathname)

    function pathOf(href: string): string {
        return new URL(href, 'http://localhost').pathname
    }
</script>

<nav class={cn('flex gap-5 overflow-x-auto border-b border-border', className)} aria-label={label}>
    {#each tabs as tab (tab.href)}
        {@const current = tab.current ?? path === pathOf(tab.href)}
        <Link
            href={tab.href}
            preserveScroll
            aria-current={current ? 'page' : undefined}
            class={cn(
                'focus-ring relative -mb-px shrink-0 rounded-sm bg-transparent px-0.5 py-2.5 text-[14px] font-medium no-underline transition-colors duration-(--dur-fast) ease-(--ease-out-soft)',
                current ? 'text-foreground' : 'text-muted-foreground hover:text-foreground'
            )}
        >
            {tab.label}
            <span
                aria-hidden="true"
                class={cn('absolute inset-x-0 bottom-0 h-0.5 bg-primary transition-opacity duration-(--dur-fast)', current ? 'opacity-100' : 'opacity-0')}
            ></span>
        </Link>
    {/each}
</nav>
