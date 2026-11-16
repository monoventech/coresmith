<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import type { EntityState } from '@/lib/fleet'
    import { Link } from '@inertiajs/svelte'
    import { ChevronRight } from '@lucide/svelte'

    let {
        servers = [],
        can
    }: {
        servers: { id: string; name: string; href: string; state: EntityState; meta: string }[]
        can: { createServer: boolean }
    } = $props()
</script>

<AppHead title="Add an app" />

<BackLink href={route('dashboard')} label="Fleet" />

<div class="mb-6 max-sm:mb-4">
    <h1 class="type-h1 m-0 max-sm:text-[24px]">Add an app</h1>
    <p class="type-body-sm mt-1.5 text-muted-foreground">Pick the server that runs it.</p>
</div>

{#if servers.length === 0}
    <EmptyState
        title="No server can take an app yet."
        description="Apps run on a server Coresmith has set up. Provision one, or connect a server you already have."
    >
        {#snippet action()}
            {#if can.createServer}
                <Button href={route('servers.create')}>Provision a server</Button>
            {:else}
                <p class="m-0 text-[13px] text-muted-foreground">Ask an owner or admin of your team to add one.</p>
            {/if}
        {/snippet}
    </EmptyState>
{:else}
    <ul
        class="m-0 flex max-w-2xl list-none flex-col overflow-hidden rounded-xl border border-border bg-card p-0"
        aria-label="Servers"
        data-testid="choose-server"
    >
        {#each servers as server (server.id)}
            <li class="border-t border-border first:border-t-0">
                <Link
                    href={server.href}
                    class="focus-ring flex min-h-14 items-center gap-3 px-5.5 py-3 text-foreground no-underline transition-colors duration-(--dur-fast) hover:bg-muted max-sm:px-4"
                >
                    <StateDot tone={server.state.tone} state={server.state.key} />
                    <span class="min-w-0 flex-1 truncate font-mono text-[14px] font-medium">{server.name}</span>
                    <span class="shrink-0 text-[12.5px] text-muted-foreground">{server.meta}</span>
                    <ChevronRight class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                </Link>
            </li>
        {/each}
    </ul>
{/if}
