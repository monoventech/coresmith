<script lang="ts">
    import BackLink from '@/components/BackLink.svelte'
    import CloudTaskBanner from '@/components/cloud/CloudTaskBanner.svelte'
    import DestroyServerDialog from '@/components/cloud/DestroyServerDialog.svelte'
    import HostKeyAlert from '@/components/HostKeyAlert.svelte'
    import PageTabs from '@/components/PageTabs.svelte'
    import Sparkline from '@/components/Sparkline.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import type { ServerHeader } from '@/lib/servers'
    import { router } from '@inertiajs/svelte'
    import { TriangleAlert } from '@lucide/svelte'

    let { server }: { server: ServerHeader } = $props()

    let renaming = $state(false)
    let detaching = $state(false)
    let destroying = $state(false)
    let name = $state('')
    let working = $state(false)

    const tabs = $derived([
        { label: 'Apps', href: server.tabs.apps },
        { label: 'Databases', href: server.tabs.databases },
        { label: 'Hardening', href: server.tabs.hardening },
        { label: 'Activity', href: server.tabs.activity }
    ])

    const metrics = $derived([
        { label: 'CPU · 24h', value: server.metrics.cpu, stroke: 'var(--primary)' },
        { label: 'Memory · 24h', value: server.metrics.memory, stroke: 'currentColor' },
        {
            label: 'Disk · 24h',
            value: server.metrics.disk,
            stroke: server.disk_warning ? 'var(--warning)' : 'currentColor'
        }
    ])

    function openRename() {
        name = server.name
        renaming = true
    }

    function saveName(event: SubmitEvent) {
        event.preventDefault()
        working = true
        router.put(
            route('servers.update', server.id),
            { name },
            { preserveScroll: true, onSuccess: () => (renaming = false), onFinish: () => (working = false) }
        )
    }

    function reboot() {
        router.post(route('servers.reboot', server.id), {}, { preserveScroll: true })
    }

    function prune() {
        router.post(route('servers.prune-releases', server.id), {}, { preserveScroll: true })
    }

    function detach() {
        working = true
        router.delete(route('servers.destroy', server.id), { onFinish: () => ((working = false), (detaching = false)) })
    }
</script>

<BackLink href={route('dashboard')} label="Fleet" />

<div class="mb-2 flex flex-wrap items-end justify-between gap-6">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="type-h1 m-0 font-mono font-medium">{server.name}</h1>
            <Badge variant={server.state.tone} dot pulse={server.state.key === 'provisioning'}
                >{server.state.label}</Badge
            >
        </div>
        <p class="type-body-sm mt-1.5 text-muted-foreground">{server.meta}</p>
        {#if server.cloud}
            <p class="type-body-sm mt-0.5 text-muted-foreground" data-testid="provider-line">
                {server.cloud.provider} · <span class="font-mono">{server.cloud.size}</span>{server.cloud.monthly
                    ? ` · ${server.cloud.monthly} a month before tax`
                    : ''}
            </p>
        {/if}
    </div>
    {#if server.can.update || server.can.delete}
        <div class="flex flex-wrap gap-2">
            {#if server.can.update}
                <Button variant="ghost" onclick={openRename}>Rename</Button>
                {#if server.active && server.cloud?.task?.state !== 'running'}
                    <Button variant="outline" onclick={reboot}>Reboot server</Button>
                {/if}
            {/if}
            {#if server.cloud?.can.destroy}
                <Button variant="ghost" class="text-destructive-strong" onclick={() => (destroying = true)}
                    >Destroy</Button
                >
            {:else if server.cloud ? server.cloud.can.detach : server.can.delete}
                <Button variant="ghost" class="text-destructive-strong" onclick={() => (detaching = true)}
                    >Detach</Button
                >
            {/if}
        </div>
    {/if}
</div>

{#if server.host_key}
    <HostKeyAlert
        serverId={server.id}
        serverName={server.name}
        expected={server.host_key.expected}
        observed={server.host_key.observed}
        changedAt={server.host_key.changed_at}
        canManage={server.can.update}
    />
{/if}

<CloudTaskBanner {server} ondetach={() => (detaching = true)} />

{#if server.disk_warning}
    <div
        class="tint-warning mt-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3"
        data-testid="disk-warning"
    >
        <TriangleAlert class="size-4.5 shrink-0 text-warning" />
        <span class="min-w-0 flex-1 text-[13.5px]">
            Disk is at {server.disk_warning.percent}%. Pruning keeps the last 5 releases of each app, plus the live one.
        </span>
        {#if server.can.update}
            <button type="button" class="focus-ring quick-action h-8! px-3! text-[12.5px]!" onclick={prune}
                >Prune old releases</button
            >
        {/if}
    </div>
{/if}

{#if server.active}
    <div class="mt-4 grid grid-cols-3 gap-3 max-[900px]:grid-cols-1">
        {#each metrics as metric (metric.label)}
            <div class="rounded-xl border border-border bg-card px-4.5 py-3.5">
                <div class="mb-2 flex items-baseline justify-between">
                    <span class="type-caption text-muted-foreground">{metric.label}</span>
                    <span class="font-mono text-[14px]">{metric.value.now === null ? '—' : `${metric.value.now}%`}</span
                    >
                </div>
                <Sparkline values={metric.value.series} stroke={metric.stroke} />
            </div>
        {/each}
    </div>
{/if}

<div class="mt-5.5 mb-9">
    <PageTabs {tabs} label="Server sections" />
</div>

<Dialog.Root bind:open={renaming}>
    <Dialog.Content>
        <form onsubmit={saveName}>
            <Dialog.Header>
                <Dialog.Title>Rename server</Dialog.Title>
                <Dialog.Description>The name is only for the panel, nothing on the server changes.</Dialog.Description>
            </Dialog.Header>
            <div class="mt-4">
                <Input bind:value={name} aria-label="Server name" required maxlength={255} />
            </div>
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (renaming = false)}>Cancel</Button>
                <Button type="submit" disabled={working}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>

{#if server.cloud}
    <DestroyServerDialog
        server={{ id: server.id, name: server.name }}
        provider={server.cloud.provider}
        bind:open={destroying}
        ondetach={() => (detaching = true)}
    />
{/if}

<Dialog.Root bind:open={detaching}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Detach {server.name}?</Dialog.Title>
            <Dialog.Description
                >{server.cloud?.instance
                    ? `Coresmith stops managing ${server.name}. It keeps running at ${server.cloud.provider}, and ${server.cloud.provider} keeps billing for it.`
                    : 'Coresmith stops managing this server. The VM itself, and everything on it, is untouched.'}</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (detaching = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={detach}>Detach server</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
