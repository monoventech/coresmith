<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, page } from '@inertiajs/svelte'
    import Breadcrumbs from '@/components/Breadcrumbs.svelte'
    import { SidebarTrigger } from '@/components/ui/sidebar'
    import type { BreadcrumbItem } from '@/types'

    let {
        breadcrumbs = []
    }: {
        breadcrumbs?: BreadcrumbItem[]
    } = $props()

    type Fleet = { servers: number; active: number; attention: number }

    const fleet = $derived(page.props.fleet as Fleet | null)
</script>

<header
    class="flex h-14 shrink-0 items-center justify-between gap-4 border-b border-border px-6 transition-[width,height] ease-linear"
>
    <div class="flex min-w-0 items-center gap-2">
        <SidebarTrigger class="-ml-1" />
        {#if breadcrumbs && breadcrumbs.length > 0}
            <Breadcrumbs {breadcrumbs} />
        {/if}
    </div>

    {#if fleet && fleet.servers > 0}
        <Link
            href={route('dashboard')}
            class="flex shrink-0 items-center gap-4 rounded-md px-2 py-1 text-xs outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
            data-testid="fleet-status"
        >
            <span class="flex items-center gap-1.5">
                <span class="status-dot-pulse size-1.5 rounded-full bg-success" aria-hidden="true"></span>
                <span class="text-muted-foreground">
                    {fleet.active} of {fleet.servers} up
                </span>
            </span>
            {#if fleet.attention > 0}
                <span class="flex items-center gap-1.5">
                    <span class="size-1.5 rounded-full bg-warning" aria-hidden="true"></span>
                    <span class="font-medium text-warning-strong">{fleet.attention} need attention</span>
                </span>
            {/if}
        </Link>
    {/if}
</header>
