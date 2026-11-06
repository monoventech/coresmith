<script lang="ts">
    import { Link, router } from '@inertiajs/svelte'
    import { onMount } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import ActivityRow from '@/components/fleet/ActivityRow.svelte'
    import MetricBar from '@/components/fleet/MetricBar.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { ActivityItem, ServerCard } from '@/lib/fleet'
    import { follow } from '@/lib/live.svelte'
    import { toneText } from '@/lib/fleet'
    import { cn } from '@/lib/utils'

    type Tile = { id: string; label: string; value: string; sub: string; tone: 'default' | 'warning'; href: string }

    let {
        counts,
        live = null,
        tiles = [],
        servers = [],
        activity = [],
        can
    }: {
        hasTeam: boolean
        counts: { servers: number; applications: number }
        live: { application: string; server: string; phase: string; href: string } | null
        tiles: Tile[]
        servers: ServerCard[]
        activity: ActivityItem[]
        can: { createServer: boolean }
    } = $props()

    let deployFrom = $state<ServerCard | null>(null)
    let deploying = $state<string | null>(null)

    onMount(() =>
        follow({ only: ['counts', 'live', 'tiles', 'servers', 'activity', 'panel'], paused: () => deploying !== null })
    )

    function plural(count: number, one: string, many: string): string {
        return `${count} ${count === 1 ? one : many}`
    }

    function quickDeploy(event: MouseEvent, server: ServerCard) {
        event.stopPropagation()

        if (server.applications.length === 1) {
            deploy(server.applications[0].deploy_href)

            return
        }

        deployFrom = server
    }

    function deploy(href: string) {
        deploying = href
        router.post(href, { from: 'fleet' }, { onFinish: () => ((deploying = null), (deployFrom = null)) })
    }
</script>

<AppHead title="Fleet" />

<div data-screen="fleet">
    <div class="mb-7 flex flex-wrap items-end justify-between gap-6 max-sm:mt-3.5 max-sm:mb-4">
        <div>
            <h1 class="type-h1 m-0 max-sm:text-[26px]">Your <MarkerUnderline>fleet</MarkerUnderline></h1>
            <p class="type-body-sm mt-1.5 text-muted-foreground max-sm:mt-1 max-sm:text-[13px]">
                {plural(counts.servers, 'server', 'servers')} · {plural(
                    counts.applications,
                    'application',
                    'applications'
                )} <span class="max-sm:hidden">· everything deployed from git</span>
            </p>
        </div>
        {#if can.createServer && servers.length > 0}
            <Button href={route('servers.create')} class="max-sm:hidden">Provision a server</Button>
        {/if}
    </div>

    {#if servers.length === 0}
        <EmptyState
            title="No servers yet."
            description="Coresmith deploys to machines you connect. Provision your first one and it is hardened before anything runs on it."
        >
            {#snippet action()}
                {#if can.createServer}
                    <Button href={route('servers.create')}>Provision a server</Button>
                {:else}
                    <p class="text-[13px] text-muted-foreground">Ask an owner or admin of your team to connect one.</p>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        {#if live}
            <Link
                href={live.href}
                class="focus-ring tint-primary mb-7 flex w-full items-center gap-3 rounded-xl border px-4.5 py-3.5 text-left text-foreground no-underline max-sm:mb-4 max-sm:min-h-12 max-sm:gap-2.5 max-sm:px-3.5 max-sm:py-3"
                data-testid="live-deploy"
            >
                <span class="status-dot-working size-2 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                <span class="text-[14px] font-medium max-sm:flex-1 max-sm:text-[13.5px]"
                    >{live.application} is deploying <span class="max-sm:hidden">to {live.server}</span></span
                >
                <span class="text-[13px] text-muted-foreground max-sm:hidden">{live.phase}</span>
                <span class="ml-auto shrink-0 text-[13px] font-medium text-primary-strong max-sm:text-[12.5px]"
                    >Watch <span class="max-sm:hidden">live</span></span
                >
            </Link>
        {/if}

        <div class="mb-7 grid grid-cols-4 gap-3 max-[1080px]:grid-cols-2 max-[620px]:grid-cols-1 max-sm:hidden">
            {#each tiles as tile (tile.id)}
                <Link
                    href={tile.href}
                    data-testid="tile-{tile.id}"
                    class="focus-ring hover-card block rounded-xl border border-border bg-card px-4.5 py-4 text-left text-foreground no-underline"
                >
                    <div class="type-caption text-muted-foreground">{tile.label}</div>
                    <div
                        class={cn(
                            'mt-1 font-mono text-[26px] font-medium',
                            tile.tone === 'warning' ? 'text-warning-strong' : 'text-foreground'
                        )}
                    >
                        {tile.value}
                    </div>
                    <div class="mt-0.5 text-[11.5px] text-muted-foreground">{tile.sub}</div>
                </Link>
            {/each}
        </div>

        <div class="mb-7 grid grid-cols-2 gap-4 max-[900px]:grid-cols-1 max-sm:gap-2.5">
            {#each servers as server (server.id)}
                <article
                    class="hover-card group relative rounded-xl border border-border bg-card px-5.5 py-5 text-foreground has-[.card-link:focus-visible]:shadow-[0_0_0_2px_var(--background),0_0_0_4px_var(--ring)] max-sm:p-4"
                    data-testid="server-card"
                >
                    <div class="mb-1 flex items-center gap-2.5 max-sm:mb-0 max-sm:gap-2.25">
                        <StateDot tone={server.state.tone} state={server.state.key} class="max-sm:size-1.75" />
                        <Link
                            href={server.href}
                            class="card-link font-mono text-[15px] font-medium text-foreground no-underline outline-none after:absolute after:inset-0 after:rounded-xl after:content-[''] max-sm:text-[14px]"
                        >
                            {server.name}
                        </Link>
                        <span
                            class={cn(
                                'ml-auto text-[12px] font-medium max-sm:text-[11.5px]',
                                toneText(server.state.tone)
                            )}>{server.state.label}</span
                        >
                    </div>
                    <div class="mb-4 text-[12.5px] text-muted-foreground max-sm:hidden">
                        {server.meta || 'Waiting for its first connection'}
                    </div>
                    <div class="mt-0.75 mb-3 text-[12px] text-muted-foreground sm:hidden">
                        {server.compact_meta || 'Waiting for its first connection'}
                    </div>
                    <div class="flex gap-2.5 font-mono text-[11px] text-muted-foreground sm:hidden">
                        <span>CPU {server.cpu ?? '—'}{server.cpu === null ? '' : '%'}</span>
                        <span>MEM {server.memory ?? '—'}{server.memory === null ? '' : '%'}</span>
                        <span>DISK {server.disk ?? '—'}{server.disk === null ? '' : '%'}</span>
                    </div>
                    <div class="grid grid-cols-3 gap-3.5 max-sm:hidden">
                        <MetricBar label="CPU" value={server.cpu} />
                        <MetricBar label="Memory" value={server.memory} />
                        <MetricBar label="Disk" value={server.disk} warnAt={80} />
                    </div>
                    <div class="relative z-10 mt-4 flex gap-2 border-t border-border pt-3 max-sm:hidden">
                        {#if server.watch_href}
                            <Link href={server.watch_href} class="focus-ring quick-action">Watch deploy</Link>
                        {:else if ['pending', 'provisioning', 'failed', 'creating', 'creation_failed'].includes(server.state.key)}
                            <Link href={server.href} class="focus-ring quick-action">Continue setup</Link>
                        {:else if server.applications.length === 0}
                            <Link href={route('servers.applications.create', server.id)} class="focus-ring quick-action"
                                >Add an app</Link
                            >
                        {:else}
                            <button
                                type="button"
                                class="focus-ring quick-action"
                                onclick={(event) => quickDeploy(event, server)}>Deploy</button
                            >
                        {/if}
                        <Link href={server.logs_href} class="focus-ring quick-action">Logs</Link>
                        {#if server.cost}
                            <span
                                class="ml-auto self-center font-mono text-[12px] text-muted-foreground"
                                title="{server.provider} list price before tax">{server.cost}</span
                            >
                        {/if}
                    </div>
                </article>
            {/each}
        </div>

        <section
            class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden"
            aria-labelledby="recent-activity"
        >
            <h3 id="recent-activity" class="type-label mb-3.5 text-muted-foreground">Recent activity</h3>
            {#if activity.length === 0}
                <p class="py-2 text-[13px] text-muted-foreground">
                    Nothing has happened yet. Deploys, rollbacks and hardening changes show up here.
                </p>
            {:else}
                <div class="flex flex-col">
                    {#each activity as item, index (index)}
                        <ActivityRow {item} arrow />
                    {/each}
                </div>
            {/if}
        </section>
    {/if}
</div>

<Dialog.Root open={deployFrom !== null} onOpenChange={(open) => !open && (deployFrom = null)}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Deploy on {deployFrom?.name}</Dialog.Title>
            <Dialog.Description
                >Each app builds into a new release while the current one keeps serving.</Dialog.Description
            >
        </Dialog.Header>
        <div class="-mx-1 mt-4 flex max-h-[min(24rem,50dvh)] flex-col overflow-y-auto p-1">
            {#each deployFrom?.applications ?? [] as application (application.id)}
                <div
                    class="flex min-h-11 items-center gap-3 border-t border-border first:border-t-0"
                    data-testid="deploy-option"
                >
                    <span class="font-mono text-[13.5px] font-medium">{application.name}</span>
                    <span class="flex-1 text-[12.5px] text-muted-foreground">{application.kind}</span>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={deploying !== null}
                        onclick={() => deploy(application.deploy_href)}>Deploy</Button
                    >
                </div>
            {/each}
        </div>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (deployFrom = null)}>Cancel</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
