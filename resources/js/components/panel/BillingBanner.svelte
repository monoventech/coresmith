<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link } from '@inertiajs/svelte'
    import { OctagonAlert, TriangleAlert } from '@lucide/svelte'
    import type { BillingBannerProps } from '@/lib/billing'

    let { banner, href }: { banner: BillingBannerProps; href: string } = $props()
</script>

<div
    class="{banner.tone === 'destructive'
        ? 'tint-destructive'
        : 'tint-warning'} mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:items-start max-sm:px-3.5"
    role="status"
    data-testid="billing-banner"
>
    {#if banner.tone === 'destructive'}
        <OctagonAlert class="size-4.5 shrink-0 text-destructive max-sm:size-4" />
    {:else}
        <TriangleAlert class="size-4.5 shrink-0 text-warning max-sm:size-4" />
    {/if}
    <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]">
        <span class="font-semibold">{banner.title}</span>
        {banner.body}
    </span>
    {#if banner.action}
        <Link {href} class="focus-ring quick-action h-8! px-3! text-[12.5px]! max-sm:ml-7 max-sm:h-11!"
            >{banner.action}</Link
        >
    {/if}
</div>
