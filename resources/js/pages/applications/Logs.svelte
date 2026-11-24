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
        application,
        sources,
        processes,
        windows,
        filters
    }: {
        server: { id: string; name: string }
        application: { id: string; name: string; href: string; lines_href: string }
        sources: Explorer['sources']
        processes: Explorer['processes']
        windows: Explorer['windows']
        filters: Explorer['filters']
    } = $props()
</script>

<AppHead title="Logs · {application.name}" />

<BackLink href={application.href} label={application.name} />

<LogExplorer
    linesHref={application.lines_href}
    place={server.name}
    kicker="{application.name} on {server.name}"
    description="Requests, process output and errors, read from {server.name} as they happen."
    label="Logs for {application.name}"
    {sources}
    {processes}
    {windows}
    {filters}
/>
