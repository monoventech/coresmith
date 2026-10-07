<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import { OctagonAlert } from '@lucide/svelte'
    import { onDestroy, onMount, tick } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import PhaseIcon from '@/components/PhaseIcon.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import { realtime, realtimeConfigured } from '@/lib/echo'
    import type { EntityState } from '@/lib/fleet'
    import { cn } from '@/lib/utils'

    type Phase = { key: string; label: string; state: string; duration: string }

    type Deployment = {
        id: string
        status: string
        state: EntityState & { live: boolean }
        log: string
        phases: Phase[]
        failed_phase: string | null
        failure_reason: string | null
        reached_users: boolean
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
        back,
        streaming,
        can
    }: {
        server: { id: string; name: string; href: string }
        application: { id: string; name: string; href: string; releases_href: string; deploy_href: string; current_release: string | null }
        deployment: Deployment
        back: { label: string; href: string }
        streaming: boolean
        can: { deploy: boolean }
    } = $props()

    const POLL_MS = 3000

    const FAILURES: Record<string, string> = {
        clone: 'The clone failed',
        deps: 'Installing dependencies failed',
        build: 'The build failed',
        migrate: 'The migration failed',
        health: 'The health check failed',
        swap: 'The deploy failed after the swap'
    }

    const FIXES: Record<string, string> = {
        clone: 'the repository access',
        deps: 'the dependencies',
        build: 'the build',
        migrate: 'the migration',
        health: 'the health check'
    }

    let pushed = $state<Deployment | null>(null)
    let downgraded = $state(false)
    let terminal = $state<HTMLDivElement | null>(null)
    let now = $state(Date.now())
    let poller: ReturnType<typeof setInterval> | null = null
    let clock: ReturnType<typeof setInterval> | null = null
    let retrying = $state(false)

    const live = $derived(pushed && pushed.id === deployment.id ? pushed : deployment)
    const finished = $derived(['succeeded', 'failed', 'cancelled'].includes(live.status))
    const running = $derived(live.status === 'running' || live.status === 'pending')
    const lines = $derived(live.log === '' ? [] : live.log.split('\n'))

    const elapsed = $derived.by(() => {
        if (!live.started_at) {
            return null
        }

        const end = live.finished_at ? new Date(live.finished_at).getTime() : now
        const seconds = Math.max(0, Math.round((end - new Date(live.started_at).getTime()) / 1000))

        return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
    })

    async function followOutput() {
        const element = terminal

        if (!element) {
            return
        }

        const nearBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 80

        await tick()

        if (nearBottom || !finished) {
            element.scrollTop = element.scrollHeight
        }
    }

    function apply(next: Deployment) {
        pushed = next
        void followOutput()

        if (['succeeded', 'failed', 'cancelled'].includes(next.status)) {
            stopPolling()
            router.reload({ only: ['application'] })
        }
    }

    function startPolling() {
        if (poller || finished) {
            return
        }

        poller = setInterval(() => {
            router.reload({ only: ['deployment', 'application'], onSuccess: () => void followOutput() })
        }, POLL_MS)
    }

    function stopPolling() {
        if (poller) {
            clearInterval(poller)
            poller = null
        }
    }

    function retry() {
        retrying = true
        router.post(application.deploy_href, { from: 'app' }, { onFinish: () => (retrying = false) })
    }

    $effect(() => {
        if (finished) {
            stopPolling()
        }
    })

    onMount(() => {
        if (terminal) {
            terminal.scrollTop = terminal.scrollHeight
        }

        clock = setInterval(() => (now = Date.now()), 1000)

        const echo = streaming && realtimeConfigured() ? realtime() : null

        if (!echo) {
            startPolling()

            return
        }

        try {
            echo.private(`deployments.${deployment.id}`).listen('.deployment.updated', (event: Deployment) => apply(event))

            const socket = (echo.connector as { pusher?: { connection?: { bind(event: string, handler: () => void): void } } }).pusher?.connection

            socket?.bind('unavailable', () => ((downgraded = true), startPolling()))
            socket?.bind('failed', () => ((downgraded = true), startPolling()))
        } catch {
            downgraded = true
            startPolling()
        }
    })

    onDestroy(() => {
        stopPolling()

        if (clock) {
            clearInterval(clock)
        }

        if (streaming && realtimeConfigured()) {
            realtime()?.leave(`deployments.${deployment.id}`)
        }
    })
</script>

<AppHead title="Deploying {application.name}" />

<BackLink href={back.href} label={back.label} />

<div class="mb-6 flex flex-wrap items-end justify-between gap-6 max-sm:mb-4">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3 max-sm:gap-2.5">
            <h1 class="type-h1 m-0 max-sm:text-[24px]">
                Deploying <MarkerUnderline markClass="max-sm:hidden"><span class="font-mono font-medium">{application.name}</span></MarkerUnderline>
            </h1>
            <Badge variant={live.state.tone} dot pulse={live.state.live} data-testid="deployment-status">{live.status === 'failed' ? 'Failed' : live.state.label}</Badge>
        </div>
        <p class="type-body-sm mt-1.5 text-muted-foreground max-sm:text-[12.5px]">
            {#if live.release}<span>Release <span class="font-mono text-[13px] max-sm:text-[12px]">{live.release}</span></span>{:else}<span>Queued</span>{/if}
            {#if live.branch}<span>· <span class="max-sm:hidden">branch</span> {live.branch}</span>{/if}
            {#if live.commit}<span>· <span class="max-sm:hidden">commit</span> <span class="font-mono text-[13px] max-sm:text-[12px]">{live.commit}</span></span>{/if}
            {#if elapsed}<span class="max-sm:hidden">· {elapsed} elapsed</span>{/if}
        </p>
    </div>
    {#if live.status === 'succeeded'}
        <Button variant="outline" href={application.releases_href} class="max-sm:hidden">View releases</Button>
    {:else if live.status === 'failed' && can.deploy}
        <div class="flex gap-2 max-sm:hidden">
            {#if live.reached_users}
                <Button variant="outline" href={application.releases_href}>Roll back</Button>
            {/if}
            <Button disabled={retrying} onclick={retry}>Retry deploy</Button>
        </div>
    {/if}
</div>

{#if live.status === 'failed'}
    <div
        class="tint-destructive mb-5 flex items-start gap-3 rounded-xl border px-4.5 py-3.5 max-sm:mb-3.5 max-sm:gap-2.5 max-sm:px-3.5 max-sm:py-3"
        data-testid="failure-banner"
    >
        <OctagonAlert class="mt-0.5 size-4.5 shrink-0 text-destructive max-sm:size-4" />
        <div class="text-[13.5px] leading-[1.55] max-sm:text-[12.5px] max-sm:leading-normal" data-testid="failure-reason">
            {#if live.reached_users}
                <span class="font-semibold">{FAILURES.swap}.</span>
                {live.failure_reason ? ` ${live.failure_reason}` : ` releases/${live.release} is live, check its processes before pushing again.`}
            {:else}
                <span class="font-semibold">{FAILURES[live.failed_phase ?? ''] ?? 'The deploy failed'}, so nothing changed.</span>
                {#if live.failure_reason}
                    {` ${live.failure_reason}`}
                {:else if application.current_release}
                    <span class="max-sm:hidden">
                        The symlink still points at <span class="font-mono text-[12.5px]">{application.current_release}</span>, users never saw an error page.
                        Fix {FIXES[live.failed_phase ?? ''] ?? 'it'} and push again, or retry.
                    </span>
                    <span class="sm:hidden">The old release is still live, users saw no error page.</span>
                {:else}
                    Nothing was live yet, so no one saw an error page.
                {/if}
            {/if}
        </div>
    </div>
{/if}

<div class="grid grid-cols-[300px_1fr] items-start gap-4 max-[900px]:grid-cols-1 max-sm:gap-3.5">
    <div class="rounded-xl border border-border bg-card px-4.5 py-3.5 max-sm:px-4 max-sm:py-2.5" data-testid="phase-rail">
        {#each live.phases as phase (phase.key)}
            <div
                class={cn('flex min-h-10.5 items-center gap-2.75 max-sm:min-h-9.5 max-sm:gap-2.5', (phase.state === 'pending' || phase.state === 'skipped') && 'opacity-50')}
                data-phase={phase.key}
                data-state={phase.state}
            >
                <PhaseIcon state={phase.state} />
                <span class="flex-1 text-[13.5px] font-medium max-sm:text-[13px]">{phase.label}</span>
                <span class={cn('font-mono text-[12px] max-sm:text-[11.5px]', phase.state === 'failed' ? 'text-destructive' : 'text-muted-foreground')}>
                    {phase.state === 'failed' ? 'failed' : phase.duration}
                </span>
            </div>
        {/each}
        <p class="mt-2.5 mb-0.5 text-[12px] leading-normal text-muted-foreground max-sm:hidden">
            {live.reached_users
                ? 'Every phase finished and the swap went through, so this release is the one being served.'
                : 'The current release keeps serving traffic until the symlink swap. If any phase fails, nothing changes.'}
        </p>
    </div>

    <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
    <div
        bind:this={terminal}
        class={cn(
            'terminal h-99 max-[900px]:h-75 max-sm:px-3.5 max-sm:py-3 max-sm:text-[11px] max-sm:leading-[1.65]',
            finished ? 'max-sm:h-37.5' : 'max-sm:h-57.5'
        )}
        data-testid="deployment-log"
        tabindex="0"
        role="log"
        aria-live="polite"
        aria-label="Deployment output"
    >
        {#each lines as line, index (index)}
            <div class="whitespace-pre-wrap">{line}</div>
        {:else}
            <div class="opacity-60">Waiting for a worker to pick up the deploy…</div>
        {/each}
        {#if running}
            <div class="opacity-60" aria-hidden="true">▍</div>
        {/if}
    </div>
</div>

{#if running}
    <p class="mt-3 text-[11.5px] leading-normal text-muted-foreground sm:hidden">The current release keeps serving traffic until the symlink swap.</p>
{:else if live.status === 'succeeded'}
    <Button variant="outline" size="lg" href={application.releases_href} class="mt-3.5 w-full sm:hidden">View releases</Button>
{:else if live.status === 'failed' && can.deploy}
    {#if live.reached_users}
        <Button variant="outline" size="lg" href={application.releases_href} class="mt-3.5 w-full sm:hidden">Roll back</Button>
    {/if}
    <Button size="lg" disabled={retrying} onclick={retry} class="mt-2.5 w-full sm:hidden">Retry deploy</Button>
{/if}

<p class="sr-only" data-testid="log-transport">
    {finished ? 'Finished' : streaming && realtimeConfigured() && !downgraded ? 'Streaming live' : `Refreshing every ${POLL_MS / 1000}s`}
</p>
