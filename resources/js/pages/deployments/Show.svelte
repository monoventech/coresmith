<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import { onDestroy, onMount, tick } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import { Label } from '@/components/ui/label'
    import { realtime, realtimeConfigured } from '@/lib/echo'

    type Deployment = {
        id: string
        status: string
        log: string
        commit_hash: string | null
        commit_message: string | null
        started_at: string | null
        finished_at: string | null
        created_at: string | null
    }

    let {
        server,
        application,
        deployment,
        streaming
    }: {
        server: { id: string; name: string }
        application: { id: string; name: string }
        deployment: Deployment
        streaming: boolean
    } = $props()

    const POLL_MS = 3000

    let pushed = $state<Deployment | null>(null)
    let follow = $state(true)
    let downgraded = $state(false)
    let logElement = $state<HTMLPreElement | null>(null)
    let poller: ReturnType<typeof setInterval> | null = null

    const canStream = $derived(streaming && realtimeConfigured() && !downgraded)
    const transport = $derived(canStream ? 'websocket' : 'polling')
    const live = $derived(pushed ?? deployment)
    const finished = $derived(['succeeded', 'failed', 'cancelled'].includes(live.status))
    const lines = $derived(live.log === '' ? 0 : live.log.split('\n').length)

    async function scrollToEnd() {
        if (!follow) {
            return
        }

        await tick()
        logElement?.scrollTo({ top: logElement.scrollHeight })
    }

    function apply(next: Deployment) {
        pushed = next
        void scrollToEnd()

        if (['succeeded', 'failed', 'cancelled'].includes(next.status)) {
            stopPolling()
        }
    }

    function startPolling() {
        if (poller || finished) {
            return
        }

        poller = setInterval(() => {
            router.reload({ only: ['deployment'], onSuccess: () => void scrollToEnd() })
        }, POLL_MS)
    }

    function stopPolling() {
        if (poller) {
            clearInterval(poller)
            poller = null
        }
    }

    function downgrade() {
        downgraded = true
        startPolling()
    }

    onMount(() => {
        void scrollToEnd()

        const echo = streaming && realtimeConfigured() ? realtime() : null

        if (!echo) {
            startPolling()

            return
        }

        try {
            echo.private(`deployments.${deployment.id}`).listen('.deployment.updated', (event: Deployment) => apply(event))

            const socket = (echo.connector as { pusher?: { connection?: { bind(event: string, handler: () => void): void } } }).pusher?.connection

            socket?.bind('unavailable', downgrade)
            socket?.bind('failed', downgrade)
        } catch {
            downgrade()
        }
    })

    onDestroy(() => {
        stopPolling()

        if (streaming && realtimeConfigured()) {
            realtime()?.leave(`deployments.${deployment.id}`)
        }
    })
</script>

<AppHead title={`Deploying ${application.name}`} />

<div class="space-y-6 px-6 py-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <Heading
            title="Deployment"
            mark="Deployment"
            description={`${application.name} on ${server.name}`}
        />
        <div class="flex items-center gap-3">
            <StatusBadge status={live.status} />
            <Button variant="outline" size="sm" href={route('servers.applications.show', [server.id, application.id])}>
                Back to application
            </Button>
        </div>
    </div>

    <dl class="grid gap-4 rounded-xl border border-border bg-card p-5 sm:grid-cols-3">
        <div>
            <dt class="text-xs text-muted-foreground">Commit</dt>
            <dd class="mt-1 font-mono text-sm">{live.commit_hash?.slice(0, 8) ?? '—'}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Message</dt>
            <dd class="mt-1 truncate text-sm">{live.commit_message ?? 'No commit message'}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Started</dt>
            <dd class="mt-1 text-sm">{live.started_at ? new Date(live.started_at).toLocaleTimeString() : 'Not yet'}</dd>
        </div>
    </dl>

    <section class="rounded-xl border border-border bg-card">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
            <h2 class="text-sm font-semibold">Output</h2>
            <div class="flex items-center gap-4">
                <p class="text-xs text-muted-foreground" data-testid="log-transport">
                    {#if finished}
                        Finished · {lines} lines
                    {:else if transport === 'websocket'}
                        Streaming live · {lines} lines
                    {:else}
                        Refreshing every {POLL_MS / 1000}s · {lines} lines
                    {/if}
                </p>
                <div class="flex items-center gap-2">
                    <Checkbox id="follow-output" bind:checked={follow} aria-label="Follow output" />
                    <Label for="follow-output" class="text-xs font-normal text-muted-foreground">Follow output</Label>
                </div>
            </div>
        </header>

        <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
        <pre
            bind:this={logElement}
            data-testid="deployment-log"
            tabindex="0"
            role="log"
            aria-live="polite"
            aria-label="Deployment output"
            class="terminal max-h-[28rem] whitespace-pre-wrap">{live.log ||
                'Waiting for the deployment to start…'}</pre>
    </section>
</div>
