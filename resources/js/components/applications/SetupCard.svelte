<script lang="ts" module>
    export type AppSetup = { href: string | null; rows: { label: string; value: string }[] }
</script>

<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Button } from '@/components/ui/button'
    import { cn } from '@/lib/utils'

    let { setup, class: className }: { setup: AppSetup; class?: string } = $props()
</script>

<div
    class={cn('flex min-w-0 flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:p-4', className)}
    data-testid="setup-card"
>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div class="min-w-0">
            <h3 class="type-h3 m-0 max-sm:text-[15px]">Setup</h3>
            <p class="m-0 mt-1 text-[12.5px] text-muted-foreground">
                What Coresmith deploys and how. Changes apply on the next deploy.
            </p>
        </div>
        {#if setup.href}
            <Button href={setup.href} variant="outline" size="sm" class="max-sm:w-full">Edit setup</Button>
        {/if}
    </div>
    <dl class="m-0 grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-x-6 gap-y-3">
        {#each setup.rows as row (row.label)}
            <div class="min-w-0">
                <dt class="text-[11.5px] font-medium text-muted-foreground">{row.label}</dt>
                <dd class="m-0 mt-0.5 font-mono text-[13px] wrap-anywhere">{row.value}</dd>
            </div>
        {/each}
    </dl>
</div>
