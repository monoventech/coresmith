<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import type { ComponentProps } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import LogExplorer from '@/components/logs/LogExplorer.svelte'

    type Explorer = ComponentProps<typeof LogExplorer>

    let {
        server,
        sources,
        windows,
        filters
    }: {
        server: { id: string; name: string; href: string; lines_href: string }
        sources: Explorer['sources']
        windows: Explorer['windows']
        filters: Explorer['filters']
    } = $props()
</script>

<AppHead title="Logs · {server.name}" />

<BackLink href={server.href} label={server.name} />

<LogExplorer
    linesHref={server.lines_href}
    place={server.name}
    kicker={server.name}
    description="Every app's requests, sign-ins, blocked addresses and system warnings on {server.name}, as they happen."
    label="Logs for {server.name}"
    {sources}
    processes={[]}
    {windows}
    {filters}
/>
