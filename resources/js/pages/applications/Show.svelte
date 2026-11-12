<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Link, page, router } from '@inertiajs/svelte'
    import { OctagonAlert, TriangleAlert } from '@lucide/svelte'
    import { onDestroy, onMount } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import CronJobsCard from '@/components/applications/CronJobsCard.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import ToggleRow from '@/components/ToggleRow.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Textarea } from '@/components/ui/textarea'
    import type { CronCard, CronSend } from '@/lib/cron'
    import type { EntityState } from '@/lib/fleet'
    import { follow } from '@/lib/live.svelte'

    type Toggle = {
        key: string
        label: string
        description: string
        on: boolean
        busy_on: string
        busy_off: string
        href: string
        disabled: boolean
        starting: boolean
        failure: string | null
    }
    type LogLine = { time: string; text: string }

    let {
        server,
        place = null,
        application,
        toggles = [],
        cron,
        can
    }: {
        server: { id: string; name: string; href: string | null }
        place: { label: string; href: string | null } | null
        application: {
            id: string
            name: string
            state: EntityState
            kind: string
            runtime: string
            domain: string | null
            additional_domains: string[]
            certificates: {
                checked_at: string
                domains: Record<
                    string,
                    {
                        state: 'valid' | 'expiring' | 'expired' | 'none'
                        expires_at: string | null
                        days: number | null
                        label: string
                        title: string
                    }
                >
            } | null
            repository: string | null
            branch: string
            current_release: string | null
            stopped: boolean
            maintenance: boolean
            deploy_key: {
                fingerprint: string | null
                short: string | null
                public_key: string
                repository: string | null
            } | null
            github: {
                repository: string
                branch: string
                usable: boolean
                problem: 'removed' | 'suspended' | null
                settings_href: string
            } | null
            environment: { key: string; value: string; secret: boolean }[]
            env: string | null
            latest_deployment: { state: EntityState; href: string | null; when: string | null } | null
            running_href: string | null
            releases_href: string
            deploy_href: string
            logs_href: string
            last_failed: boolean
            last_failed_live: boolean
            last_failed_reason: string | null
            rollback_failed: { release: string; error: string; worker: string | null } | null
            oom: { process: string; when: string; limit_mb: number; size: string } | null
            runtime_eol: { runtime: string; ended: string; fix: string } | null
        }
        toggles: Toggle[]
        cron: CronCard
        can: { update: boolean; delete: boolean; deploy: boolean; manageEnvironment: boolean; viewLogs: boolean }
    } = $props()

    const DOMAIN = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9-]+)+$/i

    const certificateTones = { valid: 'success', expiring: 'warning', expired: 'destructive', none: 'warning' } as const

    let busy = $state<Record<string, boolean>>({})
    let dialog = $state<'env' | 'domain' | 'remove' | null>(null)
    let envText = $state('')
    let envError = $state<string | null>(null)
    let domainInput = $state('')
    let domainError = $state<string | null>(null)
    let working = $state(false)
    let copied = $state(false)
    let log = $state<{ lines: LogLine[]; error: string | null; loading: boolean }>({
        lines: [],
        error: null,
        loading: true
    })
    let poll: ReturnType<typeof setInterval> | null = null
    let checking: { cancel: () => void } | null = null
    let cronBusy = $state(false)
    let cronReload: { cancel: () => void } | null = null

    const domains = $derived([...(application.domain ? [application.domain] : []), ...application.additional_domains])
    const failedDeploy = $derived(
        application.last_failed_live
            ? (application.last_failed_reason ??
                  `releases/${application.current_release} is serving, read the log before pushing again.`)
            : application.current_release
              ? `releases/${application.current_release} is still live.`
              : 'Nothing was live yet.'
    )
    const rollbackTitle = $derived(
        application.rollback_failed?.worker
            ? `${application.rollback_failed.worker} did not start after the rollback.`
            : 'The rollback did not start the app.'
    )
    const starting = $derived(toggles.some((toggle) => toggle.starting))
    const failedToggles = $derived(toggles.filter((toggle) => toggle.failure))

    $effect(() => {
        if (!starting) {
            return
        }

        const timer = setInterval(() => {
            if (!Object.values(busy).some(Boolean)) {
                router.reload({ only: ['toggles'], onCancelToken: (token) => (checking = token) })
            }
        }, 3000)

        return () => clearInterval(timer)
    })

    $effect(() => {
        if (cron.state !== 'applying') {
            return
        }

        const timer = setInterval(() => {
            if (!cronBusy && !Object.values(busy).some(Boolean)) {
                router.reload({ only: ['cron'], onCancelToken: (token) => (cronReload = token) })
            }
        }, 3000)

        return () => clearInterval(timer)
    })

    const sendCron: CronSend = (visit) => {
        cronReload?.cancel()
        cronBusy = true
        visit(() => (cronBusy = false))
    }

    function retryCron() {
        sendCron((finish) => router.post(cron.sync_href, {}, { preserveScroll: true, onFinish: finish }))
    }

    function flip(toggle: Toggle, on: boolean) {
        if (busy[toggle.key]) {
            return
        }

        checking?.cancel()
        cronReload?.cancel()
        busy = { ...busy, [toggle.key]: true }
        router.put(
            toggle.href,
            { on },
            { preserveScroll: true, onFinish: () => (busy = { ...busy, [toggle.key]: false }) }
        )
    }

    function deploy() {
        working = true
        router.post(application.deploy_href, { from: 'app' }, { onFinish: () => (working = false) })
    }

    function power() {
        router.post(
            route(application.stopped ? 'servers.applications.start' : 'servers.applications.stop', [
                server.id,
                application.id
            ]),
            {},
            { preserveScroll: true }
        )
    }

    function openEnv() {
        envText = application.env ?? ''
        envError = null
        dialog = 'env'
    }

    function saveEnv(event: SubmitEvent) {
        event.preventDefault()
        working = true
        router.put(
            route('servers.applications.env.update', [server.id, application.id]),
            { env: envText },
            {
                preserveScroll: true,
                onSuccess: () => (dialog = null),
                onError: (errors) => (envError = errors.env ?? Object.values(errors)[0] ?? null),
                onFinish: () => (working = false)
            }
        )
    }

    function openDomain() {
        domainInput = ''
        domainError = null
        dialog = 'domain'
    }

    function addDomain(event: SubmitEvent) {
        event.preventDefault()
        const value = domainInput.trim().toLowerCase()

        if (!DOMAIN.test(value)) {
            domainError = "That doesn't look like a domain. Try app.example.com."

            return
        }

        working = true
        const payload = application.domain
            ? { additional_domains: [...application.additional_domains, value] }
            : { domain: value }

        router.put(route('servers.applications.update', [server.id, application.id]), payload, {
            preserveScroll: true,
            onSuccess: () => (dialog = null),
            onError: (errors) =>
                (domainError = errors.domain ?? errors.additional_domains ?? Object.values(errors)[0] ?? null),
            onFinish: () => (working = false)
        })
    }

    function remove() {
        working = true
        router.delete(route('servers.applications.destroy', [server.id, application.id]), {
            onFinish: () => ((working = false), (dialog = null))
        })
    }

    async function copyKey() {
        if (!application.deploy_key) {
            return
        }

        await navigator.clipboard.writeText(application.deploy_key.public_key)
        copied = true
        setTimeout(() => (copied = false), 2000)
    }

    async function loadLog() {
        try {
            const response = await fetch(application.logs_href, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            const body = (await response.json()) as { lines?: LogLine[]; error?: string | null; pending?: boolean }

            log = {
                lines: body.lines ?? [],
                error: body.error ?? (response.ok ? null : 'The log could not be read.'),
                loading: Boolean(body.pending)
            }
        } catch {
            log = { lines: [], error: 'The log could not be read.', loading: false }
        }
    }

    onMount(() => {
        void loadLog()
        poll = setInterval(() => {
            if (document.visibilityState === 'visible') {
                void loadLog()
            }
        }, 10_000)
    })

    onMount(() =>
        follow({
            only: ['application', 'toggles', 'panel', ...('cron' in page.props ? ['cron'] : [])],
            paused: () => working || cronBusy || Object.values(busy).some(Boolean)
        })
    )

    onDestroy(() => {
        if (poll) {
            clearInterval(poll)
        }
    })
</script>

<AppHead title={application.name} />

<BackLink href={server.href ?? route('dashboard')} label={server.href ? server.name : 'Fleet'} />

<div class="mb-6 flex flex-wrap items-end justify-between gap-6 max-sm:mb-3.5 max-sm:gap-4">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3 max-sm:gap-2.5">
            <h1 class="type-h1 m-0 font-mono font-medium max-sm:text-[24px]">{application.name}</h1>
            <Badge variant={application.state.tone} dot pulse={application.state.key === 'deploying'}
                >{application.state.label}</Badge
            >
        </div>
        <p class="type-body-sm mt-1.5 text-muted-foreground max-sm:text-[12.5px]">
            <span>{application.kind} · {application.runtime}</span>
            {#if !server.href}<span data-testid="app-server"
                    >· on <span class="font-mono text-[13px] max-sm:text-[12px]">{server.name}</span></span
                >{/if}
            {#if place}<span data-testid="app-project"
                    >· {#if place.href}<Link href={place.href} class="focus-ring rounded-sm text-muted-foreground"
                            >{place.label}</Link
                        >{:else}{place.label}{/if}</span
                >{/if}
            {#if application.domain}<span
                    >· <span class="font-mono text-[13px] max-sm:text-[12px]">{application.domain}</span>
                    <span class="max-sm:hidden">via Caddy</span></span
                >{/if}
            {#if application.current_release}<span class="max-sm:hidden"
                    >· current release <span class="font-mono text-[13px]">{application.current_release}</span></span
                >{/if}
        </p>
    </div>
    {#if application.running_href}
        <Button href={application.running_href} class="max-sm:w-full">Watch deploy</Button>
    {:else if can.deploy}
        <Button disabled={working} onclick={deploy} class="max-sm:w-full">Deploy</Button>
    {/if}
</div>

{#if application.stopped || application.maintenance}
    <div
        class="tint-warning mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:items-start max-sm:px-3.5"
        data-testid="offline-banner"
    >
        <TriangleAlert class="size-4.5 shrink-0 text-warning max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]">
            {#if application.stopped}
                This application is stopped. Visitors see a maintenance page until you start it again.
            {:else}
                Maintenance mode is on. Visitors see a maintenance page, the processes keep running so you can still
                debug.
            {/if}
        </span>
        {#if application.stopped && can.update}
            <button
                type="button"
                class="focus-ring quick-action h-8! px-3! text-[12.5px]! max-sm:ml-7 max-sm:h-11!"
                onclick={power}>Start it</button
            >
        {/if}
    </div>
{/if}

{#if application.rollback_failed}
    <div
        class="tint-destructive mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:px-3.5"
        data-testid="rollback-failed-banner"
    >
        <OctagonAlert class="size-4.5 shrink-0 text-destructive max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:text-[12.5px]">
            <span class="font-semibold">{rollbackTitle}</span>
            {` releases/${application.rollback_failed.release} is live again, but ${application.rollback_failed.worker ?? 'its processes'} did not start. ${application.rollback_failed.error}`}
        </span>
    </div>
{:else if application.last_failed && application.latest_deployment?.href}
    <div
        class="tint-destructive mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:items-start max-sm:px-3.5"
        data-testid="failed-banner"
    >
        <OctagonAlert class="size-4.5 shrink-0 text-destructive max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]">
            <span class="font-semibold"
                >{application.last_failed_live
                    ? 'The last deploy went live but did not finish.'
                    : 'The last deploy failed, so nothing changed.'}</span
            >
            {` ${failedDeploy}`}
        </span>
        <Link
            href={application.latest_deployment.href}
            class="focus-ring quick-action h-8! px-3! text-[12.5px]! max-sm:ml-7 max-sm:h-11!">Read the log</Link
        >
    </div>
{/if}

{#each failedToggles as toggle (toggle.key)}
    <div
        class="tint-destructive mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:px-3.5"
        data-testid="toggle-failed-banner"
    >
        <OctagonAlert class="size-4.5 shrink-0 text-destructive max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:text-[12.5px]">
            <span class="font-semibold">{`${toggle.label} did not start.`}</span>
            {` ${toggle.failure}`}
        </span>
    </div>
{/each}

{#if cron.error}
    <div
        class="tint-destructive mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:items-start max-sm:px-3.5"
        data-testid="cron-failed-banner"
    >
        <OctagonAlert class="size-4.5 shrink-0 text-destructive max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]">
            <span class="font-semibold">{`The cron jobs did not reach ${server.name}.`}</span>
            {` ${cron.error}`}
        </span>
        {#if can.update}
            <button
                type="button"
                class="focus-ring quick-action h-8! px-3! text-[12.5px]! max-sm:ml-7 max-sm:h-11!"
                disabled={cronBusy}
                onclick={retryCron}>Try again</button
            >
        {/if}
    </div>
{/if}

{#if application.oom}
    <div
        class="tint-warning mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:px-3.5"
        data-testid="oom-banner"
    >
        <TriangleAlert class="size-4.5 shrink-0 text-warning max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:text-[12.5px]">
            <span class="font-semibold"
                >{`The ${application.oom.process} process was killed ${application.oom.when} for going over its ${application.oom.limit_mb} MB memory limit.`}</span
            >
            {` systemd started it again. If it keeps happening, the app needs more memory than the ${application.oom.size} size gives it.`}
        </span>
    </div>
{/if}

{#if application.runtime_eol}
    <div
        class="tint-warning mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:px-3.5"
        data-testid="eol-banner"
    >
        <TriangleAlert class="size-4.5 shrink-0 text-warning max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:text-[12.5px]">
            <span class="font-semibold"
                >{`This app runs ${application.runtime_eol.runtime}, which stopped getting security fixes on ${application.runtime_eol.ended}.`}</span
            >
            {` ${application.runtime_eol.fix}`}
        </span>
    </div>
{/if}

{#if application.github?.problem}
    <div
        class="tint-warning mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:items-start max-sm:px-3.5"
        data-testid="github-banner"
    >
        <TriangleAlert class="size-4.5 shrink-0 text-warning max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]">
            <span class="font-semibold"
                >This app clones <span class="font-mono text-[12.5px] max-sm:text-[12px]"
                    >{application.github.repository}</span
                >
                through GitHub, and that connection
                {application.github.problem === 'suspended' ? 'is suspended' : 'was removed'}.</span
            >
            Public repositories still deploy, private ones need GitHub connected again.
        </span>
        <Link
            href={application.github.settings_href}
            class="focus-ring quick-action h-8! px-3! text-[12.5px]! max-sm:ml-7 max-sm:h-11!">Open Git settings</Link
        >
    </div>
{/if}

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1 max-sm:gap-3">
    <div class="flex flex-col gap-4 max-sm:gap-3">
        <div class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:p-4" data-testid="smart-toggles">
            <h3 class="type-h3 m-0 mb-1 max-sm:mb-0.5 max-sm:text-[15px]">Smart toggles</h3>
            <p class="mb-4 text-[12.5px] text-muted-foreground max-sm:mb-2 max-sm:text-[11.5px]">
                Based on what {application.kind} apps run.
                <span class="max-sm:hidden"
                    >Flipping a switch changes what runs on {server.name}, no deployment needed. Turning on Auto deploy
                    deploys the branch if the live release is behind.</span
                ><span class="sm:hidden">No deployment needed, except Auto deploy.</span>
            </p>
            <div class="flex flex-col">
                {#each toggles as toggle (toggle.key)}
                    <ToggleRow
                        label={toggle.label}
                        description={toggle.description}
                        checked={toggle.on}
                        busy={busy[toggle.key]}
                        busyDescription={toggle.on ? toggle.busy_off : toggle.busy_on}
                        disabled={!can.update || toggle.disabled}
                        onchange={(on) => flip(toggle, on)}
                        testid="toggle-{toggle.key}"
                    />
                {/each}
            </div>
        </div>

        <CronJobsCard
            {cron}
            server={server.name}
            canUpdate={can.update}
            canViewLogs={can.viewLogs}
            busy={cronBusy}
            send={sendCron}
        />
    </div>

    <div class="flex flex-col gap-4 max-sm:gap-3">
        <div class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden">
            <h3 class="type-h3 m-0 mb-1">Environment</h3>
            <p class="mb-3 text-[12.5px] text-muted-foreground">
                Root-owned, injected via systemd at start. The app cannot read its own env file.
            </p>
            {#if application.environment.length > 0}
                <div class="font-mono text-[12.5px] leading-[1.8] text-muted-foreground" data-testid="env-preview">
                    {#each application.environment as line (line.key)}
                        <div class="truncate">{line.key}={line.value}{line.secret ? ' · redacted in logs' : ''}</div>
                    {/each}
                </div>
            {:else}
                <p class="text-[12.5px] text-muted-foreground">No variables yet.</p>
            {/if}
            {#if can.manageEnvironment}
                <Button variant="outline" size="sm" class="mt-3" onclick={openEnv}>Edit variables</Button>
            {/if}
        </div>

        {#if application.github?.usable}
            <div class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden">
                <h3 class="type-h3 m-0 mb-1">Repository access</h3>
                <p class="mb-2.5 text-[12.5px] text-muted-foreground">
                    Cloned through the GitHub App with a one-hour, read-only token for this repository. Nothing
                    long-lived is stored on the server.
                </p>
                <div class="font-mono text-[12px] break-all text-muted-foreground">
                    {application.github.repository} · {application.github.branch}
                </div>
            </div>
        {:else if application.deploy_key}
            <div class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden">
                <h3 class="type-h3 m-0 mb-1">Deploy key</h3>
                <p class="mb-2.5 text-[12.5px] text-muted-foreground">
                    Per-app Ed25519, read-only. Provider tokens are never used for git operations.
                </p>
                <div
                    class="font-mono text-[12px] break-all text-muted-foreground"
                    title={application.deploy_key.fingerprint ?? undefined}
                >
                    {application.deploy_key.short}{application.deploy_key.repository
                        ? ` · ${application.deploy_key.repository}`
                        : ''}
                </div>
                <Button variant="ghost" size="sm" class="mt-2 -ml-3" onclick={copyKey}
                    >{copied ? 'Copied' : 'Copy public key'}</Button
                >
            </div>
        {/if}

        <div class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden" data-testid="domains-card">
            <div class="mb-1 flex items-center justify-between">
                <h3 class="type-h3 m-0">Domains</h3>
                {#if can.update}
                    <Button variant="ghost" size="sm" onclick={openDomain}>Add domain</Button>
                {/if}
            </div>
            {#each domains as domain, index (domain)}
                {@const certificate = application.certificates?.domains[domain]}
                <div
                    class="flex min-h-10 items-center gap-2.5 border-t border-border text-[13px]"
                    data-testid="domain-row"
                >
                    <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]">{domain}</span>
                    {#if certificate}
                        <Badge variant={certificateTones[certificate.state]} title={certificate.title}
                            >{certificate.label}</Badge
                        >
                    {/if}
                    <span class="text-[12px] text-muted-foreground">{index === 0 ? 'primary' : 'alias'}</span>
                </div>
            {:else}
                <p class="border-t border-border pt-3 text-[12.5px] text-muted-foreground">
                    No domain yet. Add one and Caddy requests the certificate.
                </p>
            {/each}
            <p class="mt-2 text-[12px] text-muted-foreground">Routed by Caddy. Certificates renew themselves.</p>
        </div>

        <div class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:p-4">
            <div class="mb-1.5 flex items-center justify-between">
                <h3 class="type-h3 m-0 max-sm:text-[15px]">Current release</h3>
                <Link
                    href={application.releases_href}
                    class="focus-ring rounded-sm text-[13px] font-medium text-primary-strong no-underline max-sm:hidden"
                    >All releases →</Link
                >
            </div>
            <div class="font-mono text-[12px] text-muted-foreground">
                {application.current_release
                    ? `current → releases/${application.current_release}`
                    : 'Nothing is live yet. Deploy to create the first release.'}
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden">
            <h3 class="type-h3 m-0 mb-2.5">Live log</h3>
            <div
                class="terminal h-30 px-3.5 py-2.5 text-[11.5px] leading-[1.7]"
                data-testid="live-log"
                role="log"
                aria-live="polite"
            >
                {#if log.loading}
                    <div class="opacity-60">reading the access log…</div>
                {:else if log.error}
                    <div class="opacity-70">{log.error}</div>
                {:else if log.lines.length === 0}
                    <div class="opacity-60">No requests in the recent access log.</div>
                {:else}
                    {#each log.lines as line, index (index)}
                        <div class="truncate">{line.time} {line.text}</div>
                    {/each}
                {/if}
            </div>
        </div>

        {#if can.update || can.delete}
            <div class="border-danger rounded-xl border bg-card px-5.5 py-5 max-sm:hidden">
                <h3 class="type-h3 m-0 mb-1">Danger zone</h3>
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Stopping puts up the maintenance page and pauses cron jobs until you start it again. Removing stops
                    the app and detaches it from this server, its releases stay on disk for 7 days.
                </p>
                <div class="flex flex-wrap gap-2">
                    {#if can.update && !application.stopped}
                        <Button variant="outline" size="sm" onclick={power}>Stop the app</Button>
                    {/if}
                    {#if can.delete}
                        <Button
                            variant="outline"
                            size="sm"
                            class="text-destructive-strong"
                            onclick={() => (dialog = 'remove')}>Remove this app</Button
                        >
                    {/if}
                </div>
            </div>
        {/if}
    </div>
</div>

<Dialog.Root open={dialog === 'env'} onOpenChange={(open) => !open && (dialog = null)}>
    <Dialog.Content>
        <form onsubmit={saveEnv}>
            <Dialog.Header>
                <Dialog.Title>Edit environment</Dialog.Title>
                <Dialog.Description
                    >Values are root-owned and injected via systemd, the app never reads the file itself. They apply at
                    the next deploy.</Dialog.Description
                >
            </Dialog.Header>
            <div class="mt-4">
                <Textarea
                    bind:value={envText}
                    oninput={() => (envError = null)}
                    rows={8}
                    class="font-mono text-[12.5px] leading-[1.7]"
                    aria-label="Environment variables"
                    aria-invalid={envError ? true : undefined}
                    spellcheck="false"
                />
                <FieldError message={envError} />
            </div>
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (dialog = null)}>Cancel</Button>
                <Button type="submit" disabled={working}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root open={dialog === 'domain'} onOpenChange={(open) => !open && (dialog = null)}>
    <Dialog.Content>
        <form onsubmit={addDomain} novalidate>
            <Dialog.Header>
                <Dialog.Title>Add a domain</Dialog.Title>
                <Dialog.Description
                    >Point an A record at the server first, Caddy handles the certificate.</Dialog.Description
                >
            </Dialog.Header>
            <div class="mt-4">
                <Input
                    bind:value={domainInput}
                    oninput={() => (domainError = null)}
                    placeholder="app.example.com"
                    aria-label="Domain"
                    aria-invalid={domainError ? true : undefined}
                    autocomplete="off"
                />
                <FieldError message={domainError} />
            </div>
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (dialog = null)}>Cancel</Button>
                <Button type="submit" disabled={working}>Add domain</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root open={dialog === 'remove'} onOpenChange={(open) => !open && (dialog = null)}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Remove {application.name}?</Dialog.Title>
            <Dialog.Description
                >The app is stopped and detached from {server.name}. Its releases stay on disk for 7 days in case you
                change your mind.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (dialog = null)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={remove}>Remove the app</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
