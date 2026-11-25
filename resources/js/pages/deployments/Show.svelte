<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import { CircleSlash, OctagonAlert, TriangleAlert } from '@lucide/svelte'
    import { onDestroy, onMount, untrack } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import StreamedLog from '@/components/logs/StreamedLog.svelte'
    import PhaseIcon from '@/components/PhaseIcon.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import { realtime, realtimeConfigured } from '@/lib/echo'
    import type { EntityState } from '@/lib/fleet'
    import { GAP_MS, LogFeed } from '@/lib/logs/feed'
    import { Reveal } from '@/lib/logs/reveal.svelte'
    import { cn } from '@/lib/utils'

    type Phase = { key: string; label: string; state: string; duration: string }

    type Deployment = {
        id: string
        status: string
        state: EntityState & { live: boolean }
        log: string
        log_bytes: number
        phases: Phase[]
        failed_phase: string | null
        failure_reason: string | null
        live_answer: { status: number; detail: string | null } | null
        start_failed: boolean
        failed_worker: string | null
        failed_step: string | null
        reached_users: boolean
        cancel_requested: boolean
        cancelled_by: string | null
        cancellable: boolean
        release: string | null
        branch: string | null
        commit: string | null
        commit_message: string | null
        tag: string | null
        started_at: string | null
        finished_at: string | null
        created_at: string | null
    }

    let {
        application,
        deployment,
        deployment_links,
        back,
        streaming,
        can
    }: {
        server: { id: string; name: string; href: string | null }
        application: {
            id: string
            name: string
            href: string
            releases_href: string
            deploy_href: string
            setup_href: string | null
            script_href: string | null
            current_release: string | null
        }
        deployment: Deployment
        deployment_links: { cancel: string; log: string }
        back: { label: string; href: string }
        streaming: boolean
        can: { deploy: boolean }
    } = $props()

    const FOLLOW_MS = 1000
    const ENDED = ['succeeded', 'failed', 'cancelled']

    const FAILURES: Record<string, string> = {
        clone: 'The clone failed',
        storage: "Preparing the app's storage failed",
        deps: 'Installing dependencies failed',
        build: 'The build failed',
        script: 'Your deploy script failed',
        migrate: 'The migration failed',
        health: 'The health check failed',
        swap: 'The deploy failed after going live',
        watch: 'The processes did not start'
    }

    const FIXES: Record<string, string> = {
        clone: 'the repository access',
        storage: "the app's storage",
        deps: 'the dependencies',
        build: 'the build',
        script: 'the deploy script',
        migrate: 'the migration',
        health: 'the health check'
    }

    type Status = Omit<Deployment, 'log'>

    let status = $state<Status | null>(null)
    let downgraded = $state(false)
    let now = $state(Date.now())
    let poller: ReturnType<typeof setInterval> | null = null
    let clock: ReturnType<typeof setInterval> | null = null
    let retrying = $state(false)
    let cancelling = $state(false)
    let phaseAnnouncement = $state('')
    let feed: LogFeed<Status> | null = null
    let reloaded = false

    const output = new Reveal(untrack(() => deployment.log))
    const deploymentId = $derived(deployment.id)
    const logHref = $derived(deployment_links.log)
    const live = $derived(status && status.id === deployment.id ? status : deployment)
    const finished = $derived(ENDED.includes(live.status))
    const running = $derived(live.status === 'running' || live.status === 'pending')
    const runningPhase = $derived(live.phases.find((phase) => phase.state === 'running')?.label ?? null)
    const filename = $derived(`${application.name}-${live.release ?? 'deploy'}.log`.replace(/[^A-Za-z0-9._-]+/g, '-'))
    const stopping = $derived(live.cancel_requested || cancelling)
    const liveFailure = $derived(
        live.failed_worker ? `${live.failed_worker} did not start` : live.start_failed ? FAILURES.watch : FAILURES.swap
    )
    const fix = $derived(
        (live.failed_step ?? live.failed_phase) === 'script' && application.script_href
            ? { label: 'Edit script', href: application.script_href }
            : application.setup_href
              ? { label: 'Edit setup', href: application.setup_href }
              : null
    )

    const elapsed = $derived.by(() => {
        if (!live.started_at) {
            return null
        }

        const end = live.finished_at ? new Date(live.finished_at).getTime() : now
        const seconds = Math.max(0, Math.round((end - new Date(live.started_at).getTime()) / 1000))

        return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
    })

    function applyStatus(next: Status) {
        if (next.id !== untrack(() => deployment.id)) {
            return
        }

        status = next
        feed?.behind(next.log_bytes, ENDED.includes(next.status) ? 0 : GAP_MS)

        if (ENDED.includes(next.status)) {
            stopPolling()

            if (!reloaded) {
                reloaded = true
                router.reload({ only: ['application'] })
            }
        }
    }

    function startPolling() {
        if (poller || untrack(() => finished)) {
            return
        }

        poller = setInterval(() => {
            if (document.visibilityState === 'visible') {
                void feed?.fill()
            }
        }, FOLLOW_MS)
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

    function retry() {
        retrying = true
        router.post(application.deploy_href, { from: 'app' }, { onFinish: () => (retrying = false) })
    }

    function cancel() {
        cancelling = true
        router.post(
            deployment_links.cancel,
            {},
            { preserveScroll: true, onSuccess: () => (status = null), onFinish: () => (cancelling = false) }
        )
    }

    $effect(() => {
        if (finished) {
            stopPolling()
        }
    })

    $effect(() => {
        if (runningPhase) {
            phaseAnnouncement = `${runningPhase} started.`
        }
    })

    $effect(() => {
        feed?.behind(deployment.log_bytes)
    })

    $effect(() => {
        const id = deploymentId
        const href = logHref

        return untrack(() => watchDeploy(id, href))
    })

    function watchDeploy(id: string, href: string): () => void {
        const initial = deployment
        const current = new LogFeed<Status>({
            href,
            held: initial.log_bytes,
            onText: (text) => output.push(text),
            onStatus: applyStatus
        })

        status = null
        downgraded = false
        reloaded = false
        output.reset(initial.log)
        feed = current

        const echo = streaming && realtimeConfigured() ? realtime() : null

        if (echo) {
            try {
                echo.private(`deployments.${id}`)
                    .listen('.deployment.updated', (event: Status) => applyStatus(event))
                    .listen('.deployment.log', (event: { offset: number; text: string }) =>
                        current.receive(event.offset, event.text)
                    )
                    .subscribed(() => void current.fill())
                    .error(downgrade)

                const socket = (
                    echo.connector as { pusher?: { connection?: { bind(event: string, handler: () => void): void } } }
                ).pusher?.connection

                socket?.bind('unavailable', downgrade)
                socket?.bind('failed', downgrade)
            } catch {
                downgrade()
            }
        } else {
            startPolling()
        }

        return () => {
            current.close()
            stopPolling()

            if (echo) {
                echo.leave(`deployments.${id}`)
            }
        }
    }

    onMount(() => {
        clock = setInterval(() => (now = Date.now()), 1000)
    })

    onDestroy(() => {
        output.destroy()

        if (clock) {
            clearInterval(clock)
        }
    })
</script>

<AppHead title="Deploying {application.name}" />

<BackLink href={back.href} label={back.label} />

<div class="mb-6 flex flex-wrap items-end justify-between gap-6 max-sm:mb-4">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3 max-sm:gap-2.5">
            <h1 class="type-h1 m-0 max-sm:text-[24px]">
                Deploying <span class="font-mono font-medium">{application.name}</span>
            </h1>
            <Badge variant={live.state.tone} dot pulse={live.state.live} data-testid="deployment-status"
                >{live.status === 'failed' ? 'Failed' : live.state.label}</Badge
            >
        </div>
        <p class="type-body-sm mt-1.5 text-muted-foreground max-sm:text-[12.5px]">
            {#if live.release}<span
                    >Release <span class="font-mono text-[13px] max-sm:text-[12px]">{live.release}</span></span
                >{:else}<span>Queued</span>{/if}
            {#if live.branch}<span>· <span class="max-sm:hidden">branch</span> {live.branch}</span>{/if}
            {#if live.commit}<span
                    >· <span class="max-sm:hidden">commit</span>
                    <span class="font-mono text-[13px] max-sm:text-[12px]">{live.commit}</span></span
                >{/if}
            {#if elapsed}<span class="max-sm:hidden">· {elapsed} elapsed</span>{/if}
        </p>
    </div>
    {#if running && can.deploy && (live.cancellable || live.cancel_requested)}
        <Button variant="outline" class="max-sm:hidden" disabled={stopping} onclick={cancel}
            >{stopping ? 'Cancelling…' : 'Cancel'}</Button
        >
    {:else if live.status === 'succeeded'}
        <Button variant="outline" href={application.releases_href} class="max-sm:hidden">View releases</Button>
    {:else if live.status === 'cancelled' && can.deploy}
        <Button disabled={retrying} onclick={retry} class="max-sm:hidden">Deploy again</Button>
    {:else if live.status === 'failed' && can.deploy}
        <div class="flex gap-2 max-sm:hidden">
            {#if live.reached_users}
                <Button variant="outline" href={application.releases_href}>Roll back</Button>
            {/if}
            {#if fix}
                <Button variant="outline" href={fix.href}>{fix.label}</Button>
            {/if}
            <Button disabled={retrying} onclick={retry}>Retry deploy</Button>
        </div>
    {/if}
</div>

{#if live.status === 'succeeded' && live.live_answer}
    {#if live.live_answer.status >= 500}
        <div
            class="tint-warning mb-5 flex items-start gap-3 rounded-xl border px-4.5 py-3.5 max-sm:mb-3.5 max-sm:gap-2.5 max-sm:px-3.5 max-sm:py-3"
            data-testid="deploy-live-answer"
        >
            <TriangleAlert class="mt-0.5 size-4.5 shrink-0 text-warning max-sm:size-4" />
            <div class="min-w-0 text-[13.5px] leading-[1.55] max-sm:text-[12.5px] max-sm:leading-normal">
                <span class="font-semibold">{`Live, but the app answered ${live.live_answer.status}.`}</span>
                {#if live.live_answer.detail}
                    The app logged:
                    <span class="font-mono text-[12.5px] wrap-anywhere max-sm:text-[12px]"
                        >{live.live_answer.detail}</span
                    >
                {/if}
            </div>
        </div>
    {:else}
        <p class="mb-5 text-[12.5px] text-muted-foreground max-sm:mb-3.5" data-testid="deploy-live-answer">
            {`The app answered ${live.live_answer.status} once live.`}
        </p>
    {/if}
{/if}

{#if live.status === 'failed'}
    <div
        class="tint-destructive mb-5 flex items-start gap-3 rounded-xl border px-4.5 py-3.5 max-sm:mb-3.5 max-sm:gap-2.5 max-sm:px-3.5 max-sm:py-3"
        data-testid="failure-banner"
        role="alert"
    >
        <OctagonAlert class="mt-0.5 size-4.5 shrink-0 text-destructive max-sm:size-4" />
        <div
            class="text-[13.5px] leading-[1.55] max-sm:text-[12.5px] max-sm:leading-normal"
            data-testid="failure-reason"
        >
            {#if live.reached_users}
                <span class="font-semibold">{liveFailure}.</span>
                {live.failure_reason
                    ? ` ${live.failure_reason}`
                    : ` releases/${live.release} is live. Check its processes before pushing again.`}
            {:else}
                <span class="font-semibold"
                    >{FAILURES[live.failed_step ?? live.failed_phase ?? ''] ?? 'The deploy failed'}, so nothing changed.</span
                >
                {#if live.failure_reason}
                    {` ${live.failure_reason}`}
                {:else if application.current_release}
                    <span class="max-sm:hidden">
                        <span class="font-mono text-[12.5px]">{application.current_release}</span> is still live, so
                        visitors saw no error page. Fix {FIXES[live.failed_step ?? live.failed_phase ?? ''] ?? 'it'} and push
                        again, or retry.
                    </span>
                    <span class="sm:hidden">The old release is still live, so visitors saw no error page.</span>
                {:else}
                    Nothing was live yet, so no one saw an error page.
                {/if}
            {/if}
        </div>
    </div>
{:else if live.status === 'cancelled'}
    <div
        class="mb-5 flex items-start gap-3 rounded-xl border border-border bg-card px-4.5 py-3.5 max-sm:mb-3.5 max-sm:gap-2.5 max-sm:px-3.5 max-sm:py-3"
        data-testid="cancelled-strip"
    >
        <CircleSlash class="mt-0.5 size-4.5 shrink-0 text-muted-foreground max-sm:size-4" />
        <div class="text-[13.5px] leading-[1.55] max-sm:text-[12.5px] max-sm:leading-normal">
            <span class="font-semibold"
                >{live.cancelled_by
                    ? `${live.cancelled_by} cancelled this deploy, so nothing changed.`
                    : 'This deploy was cancelled, so nothing changed.'}</span
            >
            {#if application.current_release}
                <span
                    ><span class="font-mono text-[12.5px]">{application.current_release}</span> is still live, so visitors
                    saw no change.</span
                >
            {:else}
                <span>Nothing was live yet.</span>
            {/if}
        </div>
    </div>
{/if}

<div class="grid grid-cols-[300px_1fr] items-start gap-4 max-[900px]:grid-cols-1 max-sm:gap-3.5">
    <div
        class="rounded-xl border border-border bg-card px-4.5 py-3.5 max-sm:px-4 max-sm:py-2.5"
        data-testid="phase-rail"
    >
        {#each live.phases as phase (phase.key)}
            <div
                class={cn(
                    'flex min-h-10.5 items-center gap-2.75 max-sm:min-h-9.5 max-sm:gap-2.5',
                    (phase.state === 'pending' || phase.state === 'skipped') && 'text-muted-foreground'
                )}
                data-phase={phase.key}
                data-state={phase.state}
            >
                <PhaseIcon state={phase.state} />
                <span class="flex-1 text-[13.5px] font-medium max-sm:text-[13px]">{phase.label}</span>
                <span
                    class={cn(
                        'font-mono text-[12px] max-sm:text-[11.5px]',
                        phase.state === 'failed' ? 'text-destructive-strong' : 'text-muted-foreground'
                    )}
                >
                    {phase.state === 'failed' ? 'failed' : phase.state === 'cancelled' ? 'cancelled' : phase.duration}
                </span>
            </div>
        {/each}
        <p class="mt-2.5 mb-0.5 text-[12px] leading-normal text-muted-foreground max-sm:hidden">
            {live.reached_users
                ? "Every phase finished and this release went live, so it's the one being served."
                : 'The current release keeps serving traffic until the new one goes live. If any phase fails, nothing changes.'}
        </p>
    </div>

    <StreamedLog
        log={output}
        {running}
        {filename}
        label="Deployment output"
        empty="Waiting for a worker to pick up the deploy…"
        class={cn(
            'h-99 max-[900px]:h-75 max-sm:px-3.5 max-sm:py-3 max-sm:text-[11px] max-sm:leading-[1.65]',
            finished ? 'max-sm:h-37.5' : 'max-sm:h-57.5'
        )}
    />
</div>

{#if running}
    <p class="mt-3 text-[11.5px] leading-normal text-muted-foreground sm:hidden">
        The current release keeps serving traffic until the new one goes live.
    </p>
{:else if live.status === 'succeeded'}
    <Button variant="outline" size="lg" href={application.releases_href} class="mt-3.5 w-full sm:hidden"
        >View releases</Button
    >
{:else if live.status === 'failed' && can.deploy}
    {#if live.reached_users}
        <Button variant="outline" size="lg" href={application.releases_href} class="mt-3.5 w-full sm:hidden"
            >Roll back</Button
        >
    {/if}
    {#if fix}
        <Button variant="outline" size="lg" href={fix.href} class="mt-2.5 w-full sm:hidden">{fix.label}</Button>
    {/if}
    <Button size="lg" disabled={retrying} onclick={retry} class="mt-2.5 w-full sm:hidden">Retry deploy</Button>
{:else if live.status === 'cancelled' && can.deploy}
    <Button size="lg" disabled={retrying} onclick={retry} class="mt-3.5 w-full sm:hidden">Deploy again</Button>
{/if}

<p class="sr-only" data-testid="log-transport">
    {finished
        ? 'Finished'
        : streaming && realtimeConfigured() && !downgraded
          ? 'Streaming live'
          : 'Following every second'}
</p>
<p class="sr-only" aria-live="polite" data-testid="phase-announcement">{phaseAnnouncement}</p>
