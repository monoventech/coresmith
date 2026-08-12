<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import PageTabs from '@/components/PageTabs.svelte'

    let {
        serverId,
        canUpdate = false,
        class: className = 'mt-6 mb-8'
    }: {
        serverId: string
        canUpdate?: boolean
        class?: string
    } = $props()

    const tabs = $derived([
        { label: 'Overview', href: route('servers.show', serverId), exact: true },
        { label: 'Applications', href: route('servers.applications.index', serverId) },
        { label: 'Databases', href: route('servers.databases.index', serverId) },
        { label: 'Firewall', href: route('servers.firewall.index', serverId) },
        ...(canUpdate ? [{ label: 'Settings', href: route('servers.edit', serverId) }] : [])
    ])
</script>

<PageTabs class={className} {tabs} />
