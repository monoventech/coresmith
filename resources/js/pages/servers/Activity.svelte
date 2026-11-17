<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import ActivityRow from '@/components/fleet/ActivityRow.svelte'
    import ServerHeaderView from '@/components/servers/ServerHeader.svelte'
    import type { ActivityItem } from '@/lib/fleet'
    import type { ServerHeader } from '@/lib/servers'
    import { cn } from '@/lib/utils'

    let {
        server,
        activity = [],
        show = 'everything'
    }: { server: ServerHeader; activity: ActivityItem[]; show?: 'everything' | 'alerts' } = $props()

    const filters = $derived([
        { key: 'everything', label: 'Everything', href: route('servers.activity', { server: server.key }) },
        { key: 'alerts', label: 'Alerts', href: route('servers.activity', { server: server.key, show: 'alerts' }) }
    ])
</script>

<AppHead title="{server.name} activity" />

<ServerHeaderView
    {server}
    primary={server.active && server.can.createApplication
        ? { label: 'Add an app', href: route('servers.applications.create', server.key) }
        : null}
/>

<nav class="mb-4 flex flex-wrap gap-2" aria-label="Show">
    {#each filters as chip (chip.key)}
        {@const selected = chip.key === show}
        <Link
            href={chip.href}
            preserveScroll
            aria-current={selected ? 'page' : undefined}
            class={cn(
                'focus-ring inline-flex h-8 items-center rounded-md border px-3 text-[12.5px] font-medium no-underline transition-colors duration-(--dur-fast)',
                selected ? 'chip-selected text-foreground' : 'border-border bg-transparent text-muted-foreground'
            )}>{chip.label}</Link
        >
    {/each}
</nav>

<div class="rounded-xl border border-border bg-card px-5.5 py-2" data-testid="server-activity">
    {#each activity as item, index (index)}
        <ActivityRow {item} last={index === activity.length - 1} />
    {:else}
        <p class="py-3 text-[13.5px] text-muted-foreground">
            {#if show === 'alerts'}
                No alerts on this server yet. When it crosses one of its monitors' limits, the alert and its recovery
                show up here.
            {:else}
                Nothing has happened on this server yet. Deploys, reboots and firewall changes show up here.
            {/if}
        </p>
    {/each}
</div>

{#if show === 'alerts'}
    <p class="mt-3 text-[12.5px] text-muted-foreground">
        Alerts follow the limits in <Link href={route('settings.notifications')}>Settings, Notifications</Link>.
    </p>
{/if}
