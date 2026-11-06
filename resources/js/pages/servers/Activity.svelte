<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import ActivityRow from '@/components/fleet/ActivityRow.svelte'
    import ServerHeaderView from '@/components/servers/ServerHeader.svelte'
    import type { ActivityItem } from '@/lib/fleet'
    import type { ServerHeader } from '@/lib/servers'

    let { server, activity = [] }: { server: ServerHeader; activity: ActivityItem[] } = $props()
</script>

<AppHead title="{server.name} activity" />

<ServerHeaderView {server} />

<div class="rounded-xl border border-border bg-card px-5.5 py-2" data-testid="server-activity">
    {#each activity as item, index (index)}
        <ActivityRow {item} last={index === activity.length - 1} />
    {:else}
        <p class="py-3 text-[13.5px] text-muted-foreground">
            Nothing has happened on this server yet. Deploys, reboots and firewall changes show up here.
        </p>
    {/each}
</div>
