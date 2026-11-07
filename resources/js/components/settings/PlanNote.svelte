<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, page } from '@inertiajs/svelte'

    let { note }: { note: string } = $props()

    const billing = $derived(page.props.billing)
    const action = $derived(billing?.read_only ? billing.banner?.action : 'Change plan')
</script>

<p class="m-0 text-[12.5px] text-muted-foreground" data-testid="plan-note">
    {note}
    {#if billing?.can_manage}
        <Link
            href={billing.href}
            class="focus-ring rounded-sm font-medium text-primary-strong no-underline underline-offset-4 hover:underline"
            >{action}</Link
        >
    {:else if !billing?.read_only}
        Ask an owner to change the plan.
    {/if}
</p>
