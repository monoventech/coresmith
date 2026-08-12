<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import HostKeyAlert from '@/components/HostKeyAlert.svelte'
    import ServerTabs from '@/components/ServerTabs.svelte'
    import MetricTile from '@/components/MetricTile.svelte'
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
        pending_host_key_fingerprint: string | null
        host_key_changed_at: string | null
        installed_runtimes: Record<string, string[]> | null
        meta: { cpu_cores?: number; memory_mb?: number; disk_gb?: number } | null
        last_connected_at: string | null
        team: { id: string; name: string | null }
    }

    interface MetricPoint {
        cpu_percent: number
        memory_percent: number
        disk_percent: number
        load_1m: number
        recorded_at: string
    }

    interface Metrics {
        latest: (MetricPoint & { network_rx_bytes: number | null; network_tx_bytes: number | null }) | null
        history: MetricPoint[]
        cores: number
    }

    let {
        server,
        metrics,
        can
    }: {
        server: ServerDetail
        metrics: Metrics
        can: { update: boolean; delete: boolean; manageFirewall: boolean }
    } = $props()

    type Severity = 'normal' | 'warning' | 'critical'

    function severityFor(value: number, warnAt: number, criticalAt: number): Severity {
        if (value >= criticalAt) {
            return 'critical'
        }

        if (value >= warnAt) {
            return 'warning'
        }

        return 'normal'
    }

    function severityLabelFor(value: number, warnAt: number, criticalAt: number): string {
        return { normal: '', warning: 'High', critical: 'Critical' }[severityFor(value, warnAt, criticalAt)]
    }

    const loadSeverity = $derived.by((): Severity => {
        const cores = metrics.cores || 1
        const ratio = (metrics.latest?.load_1m ?? 0) / cores

        if (ratio >= 2) {
            return 'critical'
        }

        if (ratio >= 1) {
            return 'warning'
        }

        return 'normal'
    })

    const loadSeverityLabel = $derived(
        { normal: '', warning: `Above ${metrics.cores || 1} cores`, critical: 'Far above capacity' }[loadSeverity]
    )

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
    {#if server.pending_host_key_fingerprint}
        <HostKeyAlert
            serverId={server.id}
            expected={server.host_key_fingerprint}
            observed={server.pending_host_key_fingerprint}
            changedAt={server.host_key_changed_at}
            canManage={can.update}
        />
    {/if}

    <div class="flex items-start justify-between gap-6">
        <Heading title={server.name} description="{server.ssh_user}@{server.ip_address}:{server.ssh_port}" variant="small" />

        <div class="flex items-center gap-3">
            <ServerStatusBadge status={server.status} />
            {#if can.update}
                <Button variant="outline" onclick={() => router.post(`/servers/${server.id}/test-connection`)}>Test connection</Button>
                <Button onclick={() => router.post(`/servers/${server.id}/provision`)}>Provision</Button>
            {/if}
        </div>
    </div>

    <ServerTabs serverId={server.id} canUpdate={can.update} />

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

        <section class="mt-6">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-base font-semibold">Resources</h3>
                <p class="text-xs text-muted-foreground" data-testid="metrics-freshness">
                    {#if metrics.latest}
                        {`Last read ${new Date(metrics.latest.recorded_at).toLocaleTimeString()} · sparklines cover the last hour`}
                    {:else}
                        No readings yet. Metrics are collected every minute once the server is active.
                    {/if}
                </p>
            </div>

            {#if metrics.latest}
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricTile
                        label="CPU"
                        value={metrics.latest.cpu_percent.toFixed(1)}
                        unit="%"
                        series={metrics.history.map((point) => point.cpu_percent)}
                        severity={severityFor(metrics.latest.cpu_percent, 80, 95)}
                        severityLabel={severityLabelFor(metrics.latest.cpu_percent, 80, 95)}
                    />
                    <MetricTile
                        label="Memory"
                        value={metrics.latest.memory_percent.toFixed(1)}
                        unit="%"
                        series={metrics.history.map((point) => point.memory_percent)}
                        severity={severityFor(metrics.latest.memory_percent, 85, 95)}
                        severityLabel={severityLabelFor(metrics.latest.memory_percent, 85, 95)}
                    />
                    <MetricTile
                        label="Disk"
                        value={metrics.latest.disk_percent.toFixed(1)}
                        unit="%"
                        series={metrics.history.map((point) => point.disk_percent)}
                        severity={severityFor(metrics.latest.disk_percent, 80, 90)}
                        severityLabel={severityLabelFor(metrics.latest.disk_percent, 80, 90)}
                    />
                    <MetricTile
                        label="Load (1m)"
                        value={metrics.latest.load_1m.toFixed(2)}
                        series={metrics.history.map((point) => point.load_1m)}
                        severity={loadSeverity}
                        severityLabel={loadSeverityLabel}
                    />
                </div>

                <details class="mt-4 rounded-xl border border-border bg-card p-4">
                    <summary class="cursor-pointer text-sm font-medium">Readings as a table</summary>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">Server resource readings over the last hour</caption>
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="py-1 pr-4 font-medium">Time</th>
                                    <th scope="col" class="py-1 pr-4 font-medium">CPU %</th>
                                    <th scope="col" class="py-1 pr-4 font-medium">Memory %</th>
                                    <th scope="col" class="py-1 pr-4 font-medium">Disk %</th>
                                    <th scope="col" class="py-1 font-medium">Load 1m</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono text-xs tabular-nums">
                                {#each metrics.history.slice(-12).reverse() as point (point.recorded_at)}
                                    <tr class="border-t border-border">
                                        <td class="py-1 pr-4">{new Date(point.recorded_at).toLocaleTimeString()}</td>
                                        <td class="py-1 pr-4">{point.cpu_percent.toFixed(1)}</td>
                                        <td class="py-1 pr-4">{point.memory_percent.toFixed(1)}</td>
                                        <td class="py-1 pr-4">{point.disk_percent.toFixed(1)}</td>
                                        <td class="py-1">{point.load_1m.toFixed(2)}</td>
                                    </tr>
                                {/each}
                            </tbody>
                        </table>
                    </div>
                </details>
            {/if}
        </section>

    {#if server.provisioning_log}
        <section class="mt-6 rounded-xl border border-border bg-card p-6">
            <h3 class="mb-4 text-base font-semibold">Provisioning log</h3>
            <pre class="max-h-80 overflow-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">{server.provisioning_log}</pre>
        </section>
    {/if}
</div>
