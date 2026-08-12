<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import PageTabs from '@/components/PageTabs.svelte'

    let {
        serverId,
        applicationId,
        canManageEnvironment = false,
        canUpdate = false,
        class: className = 'mt-6 mb-8'
    }: {
        serverId: string
        applicationId: string
        canManageEnvironment?: boolean
        canUpdate?: boolean
        class?: string
    } = $props()

    const tabs = $derived([
        { label: 'Overview', href: route('servers.applications.show', [serverId, applicationId]), exact: true },
        { label: 'Deployments', href: route('servers.applications.deployments.index', [serverId, applicationId]) },
        { label: 'Features', href: route('servers.applications.features', [serverId, applicationId]) },
        ...(canManageEnvironment
            ? [{ label: 'Environment', href: route('servers.applications.env.show', [serverId, applicationId]) }]
            : []),
        ...(canUpdate
            ? [{ label: 'Settings', href: route('servers.applications.edit', [serverId, applicationId]) }]
            : [])
    ])
</script>

<PageTabs class={className} {tabs} />
