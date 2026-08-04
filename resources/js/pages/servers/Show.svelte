<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import ServerStatusBadge from '@/components/ServerStatusBadge.svelte'
    import { Button } from '@/components/ui/button'
    import { router } from '@inertiajs/svelte'

    interface ServerDetail {
        id: string
        name: string
        ip_address: string
        ssh_port: number
        ssh_user: string
        status: string | null
        os_name: string | null
        os_version: string | null
        provisioning_phase: number | null
        provisioning_log: string | null
        ssh_public_key: string
        host_key_fingerprint: string | null
        installed_runtimes: Record<string, string[]> | null
        meta: { cpu_cores?: number; memory_mb?: number; disk_gb?: number } | null
        last_connected_at: string | null
        team: { id: string; name: string | null }
    }

    let {
        server,
        can
    }: {
        server: ServerDetail
        can: { update: boolean; delete: boolean; manageFirewall: boolean }
    } = $props()

    let copied = $state(false)

    async function copyKey() {
        await navigator.clipboard.writeText(server.ssh_public_key)
        copied = true
        setTimeout(() => (copied = false), 2000)
    }

    const hardware = $derived(
        server.meta
            ? [`${server.meta.cpu_cores ?? '?'} vCPU`, `${server.meta.memory_mb ?? '?'} MB RAM`, `${server.meta.disk_gb ?? '?'} GB disk`].join(' · ')
            : 'Detected during provisioning'
    )
</script>

<AppHead title={server.name} />

<div class="px-6 py-8">
    <div class="mb-10 flex items-start justify-between gap-6">
        <Heading title={server.name} description="{server.ssh_user}@{server.ip_address}:{server.ssh_port}" />

        <div class="flex items-center gap-3">
            <ServerStatusBadge status={server.status} />
            {#if can.update}
                <Button variant="outline" onclick={() => router.post(`/servers/${server.id}/test-connection`)}>Test connection</Button>
                <Button onclick={() => router.post(`/servers/${server.id}/provision`)}>Provision</Button>
            {/if}
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-border bg-card p-6">
            <h3 class="mb-4 text-base font-semibold">Public key</h3>
            <p class="mb-4 text-sm leading-relaxed text-muted-foreground">
                Add this to <span class="font-mono text-xs">~/.ssh/authorized_keys</span> for
                <span class="font-mono text-xs">{server.ssh_user}</span> on the server, then test the connection.
            </p>
            <pre class="mb-4 overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">{server.ssh_public_key}</pre>
            <Button variant="outline" size="sm" onclick={copyKey}>{copied ? 'Copied' : 'Copy public key'}</Button>
        </section>

        <section class="rounded-xl border border-border bg-card p-6">
            <h3 class="mb-4 text-base font-semibold">Details</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Operating system</dt>
                    <dd>{server.os_name ? `${server.os_name} ${server.os_version ?? ''}`.trim() : 'Not detected yet'}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Hardware</dt>
                    <dd>{hardware}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Team</dt>
                    <dd>{server.team.name}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Last connected</dt>
                    <dd>{server.last_connected_at ?? 'Never'}</dd>
                </div>
                {#if server.host_key_fingerprint}
                    <div class="flex flex-col gap-1">
                        <dt class="text-muted-foreground">Host key fingerprint</dt>
                        <dd class="font-mono text-xs break-all">{server.host_key_fingerprint}</dd>
                    </div>
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        Check this against the fingerprint your provider shows in its console.
                    </p>
                {/if}
            </dl>
        </section>
    </div>

    {#if server.provisioning_log}
        <section class="mt-6 rounded-xl border border-border bg-card p-6">
            <h3 class="mb-4 text-base font-semibold">Provisioning log</h3>
            <pre class="max-h-80 overflow-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">{server.provisioning_log}</pre>
        </section>
    {/if}
</div>
