<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link } from '@inertiajs/svelte'
    import { currentUrlState } from '@/lib/currentUrl.svelte'
    import { cn } from '@/lib/utils'

    type Tab = { label: string; href: string; exact?: boolean }

    let { tabs = [], class: className }: { tabs: Tab[]; class?: string } = $props()

    const url = currentUrlState()

    function path(href: string): string {
        return href.replace(/^https?:\/\/[^/]+/, '')
    }

    function isCurrent(tab: Tab): boolean {
        const here = path(url.currentUrl)
        const target = path(tab.href)

        return tab.exact ? here === target : here === target || here.startsWith(`${target}/`)
    }
</script>

<nav class={cn('-mb-px flex gap-1 overflow-x-auto border-b border-border', className)} aria-label="Sections">
    {#each tabs as tab (tab.href)}
        {@const current = isCurrent(tab)}
        <Link
            href={tab.href}
            aria-current={current ? 'page' : undefined}
            class={cn(
                'relative shrink-0 rounded-t-md px-3 py-2.5 text-sm font-medium transition-colors outline-none',
                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                current
                    ? 'text-foreground after:absolute after:inset-x-3 after:-bottom-px after:h-0.5 after:rounded-full after:bg-primary'
                    : 'text-muted-foreground hover:text-foreground'
            )}
        >
            {tab.label}
        </Link>
    {/each}
</nav>
