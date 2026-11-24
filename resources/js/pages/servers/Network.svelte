<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import AddressesCard from '@/components/servers/AddressesCard.svelte'
    import type { Addresses } from '@/components/servers/AddressesCard.svelte'
    import FrontDoorCard from '@/components/servers/FrontDoorCard.svelte'
    import type { FrontDoor } from '@/components/servers/FrontDoorCard.svelte'
    import ServerHeaderView from '@/components/servers/ServerHeader.svelte'
    import TunnelCard from '@/components/servers/TunnelCard.svelte'
    import type { Tunnel } from '@/components/servers/TunnelCard.svelte'
    import type { ServerHeader } from '@/lib/servers'

    let {
        server,
        addresses,
        tunnel = null,
        frontDoor = null
    }: {
        server: ServerHeader
        addresses: Addresses
        tunnel?: Tunnel | null
        frontDoor?: FrontDoor | null
    } = $props()
</script>

<AppHead title="{server.name} network" />

<ServerHeaderView
    {server}
    primary={server.active && server.can.createApplication
        ? { label: 'Add an app', href: route('servers.applications.create', server.key) }
        : null}
/>

<AddressesCard {addresses} />

{#if tunnel && !server.provisioning}
    <TunnelCard {tunnel} server={server.key} name={server.name} />
{/if}

{#if frontDoor && !server.provisioning}
    <FrontDoorCard {frontDoor} server={server.key} name={server.name} />
{/if}
