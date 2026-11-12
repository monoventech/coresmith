<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import PageTabs from '@/components/PageTabs.svelte'
    import type { Snippet } from 'svelte'

    let { counts, action }: { counts: { servers: number; applications: number }; action?: Snippet } = $props()

    const tabs = $derived([
        { label: 'Servers', href: route('dashboard') },
        { label: 'Projects', href: route('projects.index') }
    ])

    function plural(count: number, one: string, many: string): string {
        return `${count} ${count === 1 ? one : many}`
    }
</script>

<div class="flex flex-wrap items-end justify-between gap-6 max-sm:mt-3.5">
    <div>
        <h1 class="type-h1 m-0 max-sm:text-[26px]">Your <MarkerUnderline>fleet</MarkerUnderline></h1>
        <p class="type-body-sm mt-1.5 text-muted-foreground max-sm:mt-1 max-sm:text-[13px]">
            {plural(counts.servers, 'server', 'servers')} · {plural(counts.applications, 'application', 'applications')}
            <span class="max-sm:hidden">· everything deployed from git</span>
        </p>
    </div>
    {@render action?.()}
</div>

<div class="mt-5.5 mb-9 max-sm:mt-4 max-sm:mb-5">
    <PageTabs {tabs} label="Fleet views" />
</div>
