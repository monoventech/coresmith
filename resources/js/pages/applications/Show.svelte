<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import type { RepositoryCheck } from '@/lib/detection'
    import { Form, Link, page, router } from '@inertiajs/svelte'
    import { ChevronRight, CircleArrowUp, OctagonAlert, TriangleAlert } from '@lucide/svelte'
    import { onDestroy, onMount } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import CronJobsCard from '@/components/applications/CronJobsCard.svelte'
    import DeployScriptCard from '@/components/applications/DeployScriptCard.svelte'
    import SetupCard from '@/components/applications/SetupCard.svelte'
    import type { AppSetup } from '@/components/applications/SetupCard.svelte'
    import type { DeployScript } from '@/components/applications/DeployScriptCard.svelte'
    import PhpLimitsCard from '@/components/applications/PhpLimitsCard.svelte'
    import type { PhpLimits } from '@/components/applications/PhpLimitsCard.svelte'
    import ExpectedDatabaseBanner from '@/components/applications/ExpectedDatabaseBanner.svelte'
    import RepositoryCheckBanner from '@/components/applications/RepositoryCheckBanner.svelte'
    import RuntimeVersionPicker from '@/components/applications/RuntimeVersionPicker.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import DnsRecords from '@/components/DnsRecords.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import TagEditor from '@/components/projects/TagEditor.svelte'
    import ToggleRow from '@/components/ToggleRow.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button, buttonVariants } from '@/components/ui/button'
    import * as Collapsible from '@/components/ui/collapsible'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Textarea } from '@/components/ui/textarea'
    import type { CronCard, CronSend } from '@/lib/cron'
    import type { ExpectedDatabase } from '@/lib/databases'
    import type { DnsRecord, DnsTarget } from '@/lib/dns'
    import { namedTargets } from '@/lib/dns'
    import type { EntityState } from '@/lib/fleet'
    import type { RuntimeChoices } from '@/lib/runtimes'
    import { follow } from '@/lib/live.svelte'
    import { cn } from '@/lib/utils'
    import { REFRESH_HEADERS } from '@/lib/visits'

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
        failure_log: string | null
    }
    type LogLine = { time: string; text: string }
    type Release = {
        id: string
        commit: string | null
        message: string | null
        author: string | null
        branch: string | null
        when: string | null
        full: string | null
    }

    let {
        server,
        place = null,
        tag_suggestions = [],
        deploy_script,
        setup,
        application,
        toggles = [],
        addresses,
        cron,
        php = null,
        can,
        runtime_choice = null,
        held = null
    }: {
        server: { id: string; name: string; href: string | null }
        place: { label: string; href: string | null } | null
        application: {
            id: string
            name: string
            tags: string[]
            tags_href: string | null
            state: EntityState
            kind: string
            runtime: string
            domain: string | null
            additional_domains: string[]
            redirects: { domain: string; to: string }[]
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
            domain_reach: {
                domain: string
                ready: boolean | null
                problems: string[]
                notes: string[]
                records: DnsRecord[]
                checked: string | null
            }[]
            uptime: {
                percent: string
                days: { date: string; state: 'full' | 'gaps' | 'down' | 'none'; title: string }[]
                label: string
                note: string
            } | null
            domain_check_href: string
            dns_targets: DnsTarget[]
            repository: string | null
            branch: string
            current_release: string | null
            release: Release | null
            rollback_href: string | null
            stopped: boolean
            maintenance: boolean
            deploy_key: {
                fingerprint: string | null
                short: string | null
                public_key: string
            } | null
            github: {
                repository: string
                branch: string
                usable: boolean
                problem: 'removed' | 'suspended' | null
                settings_href: string
            } | null
            environment: { key: string; value: string; secret: boolean; empty: boolean }[]
            databases: { name: string; href: string; status: string }[]
            expected_databases: ExpectedDatabase[]
            databases_href: string
            env: string | null
            latest_deployment: { state: EntityState; href: string | null; when: string | null } | null
            running_href: string | null
            releases_href: string
            deploy_href: string
            repository_check: RepositoryCheck | null
            logs_href: string
            last_failed: boolean
            last_failed_live: boolean
            last_failed_reason: string | null
            rollback_failed: { release: string; error: string; worker: string | null } | null
            oom: { process: string; when: string; limit_mb: number; size: string } | null
            runtime_eol: { runtime: string; ended: string; fix: string } | null
        }
        toggles: Toggle[]
        addresses: {
            tailnet: boolean
            preview: boolean
            tailnet_url: string | null
            preview_url: string | null
            problem: string | null
            href: string
        }
        cron: CronCard
        php: PhpLimits | null
        can: { update: boolean; delete: boolean; deploy: boolean; manageEnvironment: boolean; viewLogs: boolean }
        tag_suggestions: string[]
        deploy_script: DeployScript
        setup: AppSetup
        runtime_choice: (RuntimeChoices & { pin: string | null; href: string }) | null
        held: { current: string; newest: string; packages: string[]; command: string } | null
    } = $props()

    const DOMAIN = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9-]+)+$/i

    const certificateTones = { valid: 'success', expiring: 'warning', expired: 'destructive', none: 'warning' } as const
    const uptimeTones = {
        full: 'bg-success',
        gaps: 'bg-warning',
        down: 'bg-destructive',
        none: 'bg-secondary'
    } as const

    const cardAction = cn(buttonVariants({ variant: 'link' }), 'min-h-6 max-sm:min-h-11')

    let busy = $state<Record<string, boolean>>({})
    let dialog = $state<'env' | 'domain' | 'remove' | 'runtime' | 'rename' | null>(null)
    let envText = $state('')
    let envError = $state<string | null>(null)
    let domainInput = $state('')
    let domainError = $state<string | null>(null)
    let nameInput = $state('')
    let nameError = $state<string | null>(null)
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
    let checkingReach = $state(false)
    const reachChecked = $derived(application.domain_reach.filter((reading) => reading.checked !== null))
    const redirectNames = $derived(new Set(application.redirects.map((redirect) => redirect.domain)))
    const advice = $derived(
        application.domain_reach.filter(
            (reading) =>
                (reading.problems.length > 0 || reading.notes.length > 0 || reading.records.length > 0) &&
                !(
                    redirectNames.has(reading.domain) &&
                    application.certificates?.domains[reading.domain]?.state === 'valid'
                )
        )
    )

    function waitingForDns(domain: string) {
        return (
            application.certificates?.domains[domain]?.state === 'none' &&
            application.domain_reach.find((reading) => reading.domain === domain)?.ready !== true
        )
    }
    const reachWhen = $derived(reachChecked[0]?.checked === 'now' ? 'just now' : (reachChecked[0]?.checked ?? ''))
    const reachSummary = $derived(
        reachChecked.length === 0
            ? 'Not checked from outside yet.'
            : reachChecked.length === application.domain_reach.length &&
                application.domain_reach.every((reading) => reading.ready === true && reading.notes.length === 0)
              ? `Visitors reach every domain here from the internet. Checked ${reachWhen}.`
              : `Checked ${reachWhen}.`
    )
    const failedDeploy = $derived(
        application.last_failed_live
            ? (application.last_failed_reason ??
                  `releases/${application.current_release} is serving. Read the log before pushing again.`)
            : application.current_release
              ? `releases/${application.current_release} is still live.`
              : 'Nothing was live yet.'
    )
    const rollbackTitle = $derived(
        application.rollback_failed?.worker
            ? `${application.rollback_failed.worker} did not start after the rollback.`
            : 'The rollback did not start the app.'
    )
    const sourceAccess = $derived(
        !application.repository
            ? null
            : application.github?.usable
              ? 'Cloned through the GitHub App with a one-hour, read-only token for this repository. Nothing long-lived is stored on the server.'
              : application.github
                ? 'Cloned through GitHub, which needs connecting again.'
                : application.deploy_key
                  ? "Cloned with this app's deploy key, Ed25519 and read-only. Provider tokens are never used for git operations."
                  : 'Cloned over HTTPS without a key, so the repository has to be public.'
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

    let addressBusy = $state<'tailnet' | 'preview' | null>(null)

    function setAddress(kind: 'tailnet' | 'preview', on: boolean) {
        addressBusy = kind
        router.put(
            addresses.href,
            {
                tailnet: kind === 'tailnet' ? on : addresses.tailnet,
                preview: kind === 'preview' ? on : addresses.preview
            },
            { preserveScroll: true, onFinish: () => (addressBusy = null) }
        )
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

    function releaseDetail(release: Release): string {
        const where = [release.branch && `on ${release.branch}`, release.author && `by ${release.author}`]
            .filter(Boolean)
            .join(' ')
        const when = release.when ? `${release.commit || where ? ', ' : ''}${release.when}` : ''
        const text = `${where && release.commit ? ' ' : ''}${where}${when}`

        return release.commit ? text : text.charAt(0).toUpperCase() + text.slice(1)
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

    function openRename() {
        nameInput = application.name
        nameError = null
        dialog = 'rename'
    }

    function saveName(event: SubmitEvent) {
        event.preventDefault()
        working = true
        router.put(
            route('servers.applications.update', [server.id, application.id]),
            { name: nameInput },
            {
                preserveScroll: true,
                onSuccess: () => (dialog = null),
                onError: (errors) => (nameError = errors.name ?? Object.values(errors)[0] ?? null),
                onFinish: () => (working = false)
            }
        )
    }

    function checkReach() {
        router.post(
            application.domain_check_href,
            {},
            {
                preserveScroll: true,
                onStart: () => (checkingReach = true),
                onFinish: () => (checkingReach = false)
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
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...REFRESH_HEADERS }
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
            only: ['application', 'toggles', 'panel', 'app_deploy', ...('cron' in page.props ? ['cron'] : [])],
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
            <h1 class="type-h1 m-0 font-mono font-medium wrap-anywhere max-sm:text-[24px]">
                {application.name}
            </h1>
            <Badge variant={application.state.tone} dot pulse={application.state.key === 'deploying'}
                >{application.state.label}</Badge
            >
        </div>
        <p class="type-body-sm mt-1.5 text-muted-foreground max-sm:text-[12.5px]">
            <span>{application.kind} · {application.runtime}</span>
            {#if runtime_choice}<button
                    type="button"
                    class="focus-ring relative cursor-pointer rounded-sm bg-transparent p-0 text-primary-strong after:absolute after:-inset-x-2 after:-inset-y-3 after:content-['']"
                    aria-label="Change the {runtime_choice.label} version"
                    onclick={() => (dialog = 'runtime')}>Change</button
                >{/if}
            <span data-testid="app-server"
                >· on {#if server.href}<Link
                        href={server.href}
                        class="focus-ring rounded-sm font-mono text-[13px] text-muted-foreground max-sm:text-[12px]"
                        >{server.name}</Link
                    >{:else}<span class="font-mono text-[13px] max-sm:text-[12px]">{server.name}</span>{/if}</span
            >
            {#if place}<span data-testid="app-project"
                    >· {#if place.href}<Link href={place.href} class="focus-ring rounded-sm text-muted-foreground"
                            >{place.label}</Link
                        >{:else}{place.label}{/if}</span
                >{/if}
            {#if application.domain}<span class="sm:hidden"
                    >· <span class="font-mono text-[12px]">{application.domain}</span></span
                >{/if}
        </p>
        {#if application.tags.length > 0 || application.tags_href}
            <div class="mt-2" data-testid="app-tags-row">
                <TagEditor
                    name={application.name}
                    tags={application.tags}
                    href={application.tags_href}
                    suggestions={tag_suggestions}
                />
            </div>
        {/if}
    </div>
    {#if application.running_href || can.deploy || can.update}
        <div class="flex flex-wrap gap-2 max-sm:w-full">
            {#if application.running_href}
                <Button href={application.running_href} class="max-sm:w-full sm:hidden">Watch deploy</Button>
            {:else if can.deploy}
                <Button
                    disabled={working || application.repository_check !== null}
                    aria-describedby={application.repository_check ? 'repository-check-heading' : undefined}
                    onclick={deploy}
                    class="max-sm:w-full sm:hidden">Deploy</Button
                >
            {/if}
            {#if can.update}
                <Button variant="ghost" class="max-sm:hidden" onclick={openRename}>Rename</Button>
            {/if}
        </div>
    {/if}
</div>

{#if application.repository_check}
    <RepositoryCheckBanner check={application.repository_check} canDeploy={can.deploy} />
{/if}

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
        {#if setup.href}
            <Link href={setup.href} class="focus-ring quick-action h-8! px-3! text-[12.5px]! max-sm:h-11!"
                >Edit setup</Link
            >
        {/if}
    </div>
{/if}

{#each application.expected_databases as banner (banner.key)}
    <ExpectedDatabaseBanner {banner} href={application.databases_href} />
{/each}

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
        {#if toggle.failure_log}
            <Collapsible.Root class="basis-full pl-7.5 max-sm:pl-7">
                <Collapsible.Trigger
                    class="group focus-ring inline-flex min-h-8 items-center gap-1 rounded-md text-[12.5px] font-medium max-sm:min-h-11"
                    data-testid="toggle-failed-log-trigger"
                >
                    <ChevronRight
                        class="size-3.5 transition-transform duration-150 group-data-[state=open]:rotate-90 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                    <span class="group-data-[state=open]:hidden">Show the log</span>
                    <span class="hidden group-data-[state=open]:inline">Hide the log</span>
                </Collapsible.Trigger>
                <Collapsible.Content>
                    <pre
                        class="terminal mt-2 max-h-72 overflow-auto text-[11.5px] leading-[1.6] break-all whitespace-pre-wrap"
                        data-testid="toggle-failed-log">{toggle.failure_log}</pre>
                </Collapsible.Content>
            </Collapsible.Root>
        {/if}
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
            {` it was started again. If it keeps happening, the app needs more memory than the ${application.oom.size} size gives it.`}
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

{#if held}
    <div
        class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4.5 py-3 max-sm:mb-3 max-sm:px-3.5"
        data-testid="runtime-held-banner"
    >
        <CircleArrowUp class="size-4.5 shrink-0 text-muted-foreground max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:text-[12.5px]">
            <span class="font-semibold"
                >{`PHP ${held.newest} is out. composer.lock holds this app on ${held.current}.`}</span
            >
            Run <code class="font-mono text-[12.5px]">{held.command}</code>, commit composer.lock and deploy.
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
            Public repositories still deploy. Private ones need GitHub connected again.
        </span>
        <Link
            href={application.github.settings_href}
            class="focus-ring quick-action h-8! px-3! text-[12.5px]! max-sm:ml-7 max-sm:h-11!">Open Git settings</Link
        >
    </div>
{/if}

<div class="@container flex flex-col gap-6 max-sm:gap-3">
    <section aria-labelledby="app-now">
        <h2
            id="app-now"
            class="mb-2.5 text-[11.5px] font-semibold tracking-[0.04em] text-muted-foreground max-sm:hidden"
        >
            Now
        </h2>
        <div class="grid gap-4 max-sm:gap-3 @3xl:grid-cols-3">
            <div
                class="flex min-w-0 flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:p-4"
                data-testid="release-card"
            >
                <h3 class="type-h3 m-0 mb-1.5 max-sm:text-[15px]">Current release</h3>
                {#if application.release}
                    {@const release = application.release}
                    <p class="m-0 font-mono text-[13px] max-sm:text-[12px]">{release.id}</p>
                    {#if release.message}
                        <p class="m-0 mt-1.5 text-[13.5px] wrap-anywhere max-sm:text-[12.5px]">{release.message}</p>
                    {/if}
                    {#if release.commit || release.branch || release.author || release.when}
                        <p
                            class="m-0 mt-1 text-[12.5px] wrap-anywhere text-muted-foreground max-sm:text-[11.5px]"
                            title={release.full ?? undefined}
                            data-testid="release-detail"
                        >
                            {#if release.commit}<span class="font-mono text-[12px] max-sm:text-[11px]"
                                    >{release.commit}</span
                                >{/if}{releaseDetail(release)}
                        </p>
                    {/if}
                {:else}
                    <p class="m-0 text-[12.5px] text-muted-foreground">
                        Nothing is live yet. Deploy to create the first release.
                    </p>
                {/if}
                <div class="flex flex-wrap gap-x-5 gap-y-2 pt-3">
                    <Link href={application.releases_href} class={cardAction}>All releases</Link>
                    {#if application.rollback_href}
                        <Link href={application.rollback_href} class={cardAction}>Roll back</Link>
                    {/if}
                </div>
            </div>

            <div
                class="flex min-w-0 flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden"
                data-testid="domains-card"
            >
                <h3 class="type-h3 m-0 mb-1.5">Domains</h3>
                {#each domains as domain, index (domain)}
                    {@const certificate = application.certificates?.domains[domain]}
                    <div
                        class="flex min-h-10 items-center gap-2.5 border-t border-border text-[13px]"
                        data-testid="domain-row"
                    >
                        <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]" title={domain}>{domain}</span>
                        {#if certificate}
                            <Badge variant={certificateTones[certificate.state]} title={certificate.title}
                                >{certificate.label}</Badge
                            >
                        {/if}
                        <span class="text-[12px] text-muted-foreground">{index === 0 ? 'primary' : 'alias'}</span>
                    </div>
                {:else}
                    <p class="m-0 border-t border-border pt-3 text-[12.5px] text-muted-foreground">
                        No domain yet. Add one and its certificate is requested for you.
                    </p>
                {/each}
                {#each application.redirects as redirect (redirect.domain)}
                    {@const certificate = application.certificates?.domains[redirect.domain]}
                    <div
                        class="flex min-h-10 items-center gap-2.5 border-t border-border text-[13px]"
                        data-testid="domain-redirect-row"
                    >
                        <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]" title={redirect.domain}
                            >{redirect.domain}</span
                        >
                        {#if certificate && !waitingForDns(redirect.domain)}
                            <Badge variant={certificateTones[certificate.state]} title={certificate.title}
                                >{certificate.label}</Badge
                            >
                        {/if}
                        <span class="text-[12px] text-muted-foreground" title="Sends visitors to {redirect.to}"
                            >redirect<span class="sr-only"> to {redirect.to}</span></span
                        >
                    </div>
                {/each}
                {#if application.uptime}
                    <div class="mt-3 flex flex-col gap-2 border-t border-border pt-3" data-testid="app-uptime">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="font-display text-[26px] leading-none font-semibold"
                                >{application.uptime.percent}</span
                            >
                            <span class="text-[12.5px] text-muted-foreground">answering over 30 days</span>
                        </div>
                        <div role="img" aria-label={application.uptime.label} class="flex gap-0.5">
                            {#each application.uptime.days as day (day.date)}
                                <span class="h-5.5 flex-1 rounded-xs {uptimeTones[day.state]}" title={day.title}></span>
                            {/each}
                        </div>
                        <p class="m-0 text-[12px] text-muted-foreground">{application.uptime.note}</p>
                    </div>
                {/if}
                {#if domains.length > 0}
                    <p class="m-0 mt-2 text-[12px] text-muted-foreground">Certificates renew themselves.</p>
                    <p class="m-0 mt-1 text-[12px] text-muted-foreground" data-testid="domain-reach-summary">
                        {reachSummary}
                    </p>
                {/if}
                {#if can.update}
                    <div class="flex flex-wrap gap-x-5 gap-y-2 pt-3">
                        <Button variant="link" class="min-h-6" onclick={openDomain}>Add domain</Button>
                        {#if domains.length > 0}
                            <Button variant="link" class="min-h-6" disabled={checkingReach} onclick={checkReach}
                                >{reachChecked.length > 0 ? 'Check again' : 'Check from outside'}</Button
                            >
                        {/if}
                    </div>
                {/if}
            </div>

            <div
                class="flex min-w-0 flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden"
                data-testid="source-card"
            >
                <h3 class="type-h3 m-0 mb-1.5">Source</h3>
                {#if application.repository}
                    <p class="m-0 font-mono text-[13px] break-all">{application.repository}</p>
                    <p class="m-0 mt-1.5 text-[13.5px]">
                        Branch <span class="font-mono text-[12.5px]">{application.branch}</span>
                    </p>
                {:else}
                    <p class="m-0 text-[13.5px]">No repository is set.</p>
                {/if}
                {#if sourceAccess}
                    <p class="m-0 mt-1.5 text-[12.5px] text-muted-foreground">{sourceAccess}</p>
                {/if}
                {#if application.deploy_key}
                    <p
                        class="m-0 mt-1.5 font-mono text-[12px] break-all text-muted-foreground"
                        title={application.deploy_key.fingerprint ?? undefined}
                    >
                        {application.deploy_key.short}
                    </p>
                    <div class="pt-3">
                        <Button variant="link" class="min-h-6" onclick={copyKey}
                            >{copied ? 'Copied' : 'Copy public key'}</Button
                        >
                    </div>
                {/if}
            </div>

            {#if advice.length > 0}
                <div
                    class="min-w-0 rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden @3xl:col-span-3"
                    data-testid="domain-reach"
                >
                    <h3 class="type-h3 m-0 mb-1.5">Reaching your domains</h3>
                    <div class="flex flex-col gap-4">
                        {#each advice as reading (reading.domain)}
                            <div
                                class="grid min-w-0 gap-x-8 gap-y-2 @5xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]"
                                data-testid="domain-advice"
                            >
                                <div class="flex min-w-0 flex-col gap-1.5">
                                    {#each reading.problems as line (line)}
                                        <p class="m-0 max-w-[75ch] text-[12.5px] text-warning-strong">{line}</p>
                                    {/each}
                                    {#each reading.notes as line (line)}
                                        <p class="m-0 max-w-[75ch] text-[12.5px] text-muted-foreground">{line}</p>
                                    {/each}
                                </div>
                                {#if reading.records.length > 0}
                                    <DnsRecords records={reading.records} testid="domain-records" />
                                {/if}
                            </div>
                        {/each}
                    </div>
                </div>
            {/if}
        </div>
    </section>

    <div class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden" data-testid="live-log-card">
        <div class="mb-2.5 flex items-center justify-between gap-3">
            <h3 class="type-h3 m-0">Live log</h3>
            {#if !log.loading && !log.error}
                <span class="flex items-center gap-1.5 text-[12px] text-muted-foreground">
                    <span class="status-dot-pulse size-1.75 rounded-full bg-success" aria-hidden="true"></span>Following
                </span>
            {/if}
        </div>
        <div
            class="terminal h-49 px-3.5 py-2.5 text-[11.5px] leading-[1.7]"
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

    <section aria-labelledby="app-settings">
        <h2
            id="app-settings"
            class="mb-2.5 text-[11.5px] font-semibold tracking-[0.04em] text-muted-foreground max-sm:hidden"
        >
            Settings
        </h2>
        <div class="grid gap-4 max-sm:gap-3 @3xl:grid-cols-2">
            <div
                class="flex min-w-0 flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:p-4"
                data-testid="smart-toggles"
            >
                <h3 class="type-h3 m-0 mb-1 max-sm:mb-0.5 max-sm:text-[15px]">Smart toggles</h3>
                <p class="mb-4 text-[12.5px] text-muted-foreground max-sm:mb-2 max-sm:text-[11.5px]">
                    Based on what {application.kind} apps run.
                    <span class="max-sm:hidden"
                        >Flipping a switch changes what runs on {server.name}, no deployment needed. Turning on Auto
                        deploy deploys the branch if the live release is behind.</span
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
                    <ToggleRow
                        label="Reachable on your tailnet"
                        description={addresses.problem ??
                            (addresses.tailnet_url
                                ? `At ${addresses.tailnet_url}, once ${server.name}'s firewall lets your tailnet in.`
                                : `Serves the app at ${server.name}'s Tailscale address, without a domain.`)}
                        checked={addresses.tailnet}
                        busy={addressBusy === 'tailnet'}
                        disabled={!can.update}
                        onchange={(on) => setAddress('tailnet', on)}
                        testid="toggle-tailnet"
                    />
                    <ToggleRow
                        label="Preview without a domain"
                        description={addresses.preview_url
                            ? `Plain HTTP at ${addresses.preview_url}. Anyone who has the address can open it. Add a domain for HTTPS.`
                            : "Plain HTTP on the server's own address. Anyone who has the address can open it. Add a domain for HTTPS."}
                        checked={addresses.preview}
                        busy={addressBusy === 'preview'}
                        disabled={!can.update}
                        onchange={(on) => setAddress('preview', on)}
                        testid="toggle-preview"
                    />
                </div>
            </div>

            <div
                class="flex min-w-0 flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden"
                data-testid="environment-card"
            >
                <h3 class="type-h3 m-0 mb-1">Environment</h3>
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Kept out of the app's reach and handed to it at start. The app can't read its own env file.
                </p>
                {#if application.environment.length > 0}
                    <div class="font-mono text-[12.5px] leading-[1.8] text-muted-foreground" data-testid="env-preview">
                        {#each application.environment as line (line.key)}
                            <div class="truncate">
                                {line.key}={line.value}{#if line.empty}<span class="text-warning-strong"
                                        >&nbsp;· not set</span
                                    >{:else if line.secret}<span>&nbsp;· redacted in logs</span>{/if}
                            </div>
                        {/each}
                    </div>
                {:else}
                    <p class="text-[12.5px] text-muted-foreground">No variables yet.</p>
                {/if}
                {#if application.databases.length > 0}
                    <p class="mt-3 text-[12.5px] text-muted-foreground" data-testid="connected-databases">
                        Connected to {#each application.databases as database, index (database.href)}{index === 0
                                ? ''
                                : index === application.databases.length - 1
                                  ? ' and '
                                  : ', '}<Link
                                href={database.href}
                                class="focus-ring rounded-sm font-mono text-primary-strong underline-offset-4 hover:underline"
                                >{database.name}</Link
                            >{database.status === 'creating'
                                ? ' (being created)'
                                : database.status === 'failed'
                                  ? ' (not created)'
                                  : ''}{/each}. Coresmith updates {application.databases.length === 1 ? 'its' : 'their'} settings
                        here when {application.databases.length === 1 ? 'its' : 'a'} password or port changes.
                    </p>
                {/if}
                {#if can.manageEnvironment}
                    <div class="mt-auto pt-3">
                        <Button variant="link" class="min-h-6" onclick={openEnv}>Edit variables</Button>
                    </div>
                {/if}
            </div>

            <SetupCard class="@3xl:col-span-2" {setup} />

            <DeployScriptCard class="@3xl:col-span-2" script={deploy_script} name={application.name} />

            {#if php}
                <PhpLimitsCard
                    class="@3xl:col-span-2"
                    {php}
                    server={server.name}
                    href={route('servers.applications.php-limits.update', {
                        server: server.id,
                        application: application.id
                    })}
                    canUpdate={can.update}
                />
            {/if}

            <CronJobsCard
                class="@3xl:col-span-2"
                {cron}
                server={server.name}
                canUpdate={can.update}
                canViewLogs={can.viewLogs}
                busy={cronBusy}
                send={sendCron}
            />
        </div>
    </section>

    {#if can.update || can.delete}
        <div
            class="border-danger flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-xl border bg-card px-5.5 py-4.5 max-sm:hidden"
        >
            <div class="min-w-0 flex-1 basis-80">
                <h3 class="type-h3 m-0 mb-1">Danger zone</h3>
                <p class="m-0 text-[12.5px] text-muted-foreground">
                    Stopping puts up the maintenance page and pauses cron jobs until you start it again. Removing stops
                    the app and detaches it from this server. Its releases stay on disk for 7 days.
                </p>
            </div>
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

<Dialog.Root open={dialog === 'env'} onOpenChange={(open) => !open && (dialog = null)}>
    <Dialog.Content>
        <form onsubmit={saveEnv}>
            <Dialog.Header>
                <Dialog.Title>Edit environment</Dialog.Title>
                <Dialog.Description
                    >Values are handed to the app when it starts, and the app never reads the file itself. They apply at
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
                <Dialog.Description>
                    {#if application.dns_targets.length > 0}
                        Point the domain at this server with {application.dns_targets.length === 1
                            ? 'this record'
                            : 'these records'}, then add it here. The certificate is handled for you.
                    {:else}
                        Point the domain at the server first: an A record for its IPv4 address, or an AAAA record for
                        IPv6. Once the app is live, Coresmith checks the domain from outside and shows the record it
                        needs. The certificate is handled for you.
                    {/if}
                </Dialog.Description>
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
            {#if application.dns_targets.length > 0}
                <div class="mt-3">
                    <DnsRecords records={namedTargets(application.dns_targets, domainInput)} testid="domain-targets" />
                </div>
            {/if}
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (dialog = null)}>Cancel</Button>
                <Button type="submit" disabled={working}>Add domain</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root open={dialog === 'rename'} onOpenChange={(open) => !open && (dialog = null)}>
    <Dialog.Content>
        <form onsubmit={saveName}>
            <Dialog.Header>
                <Dialog.Title>Rename app</Dialog.Title>
                <Dialog.Description>The name is only for Coresmith. Nothing on the server changes.</Dialog.Description>
            </Dialog.Header>
            <div class="mt-4">
                <Label for="app-name" class="mb-1.5">App name</Label>
                <Input
                    id="app-name"
                    bind:value={nameInput}
                    oninput={() => (nameError = null)}
                    required
                    maxlength={255}
                    autocomplete="off"
                    aria-invalid={nameError ? true : undefined}
                />
                <FieldError message={nameError} />
            </div>
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (dialog = null)}>Cancel</Button>
                <Button type="submit" disabled={working}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>

{#if runtime_choice}
    <Dialog.Root open={dialog === 'runtime'} onOpenChange={(open) => !open && (dialog = null)}>
        <Dialog.Content>
            <Form
                method="put"
                action={runtime_choice.href}
                options={{ preserveScroll: true }}
                onSuccess={() => (dialog = null)}
            >
                {#snippet children({ processing, errors })}
                    <Dialog.Header>
                        <Dialog.Title>{`Change the ${runtime_choice.label} version`}</Dialog.Title>
                        <Dialog.Description>Coresmith uses it from the next deploy.</Dialog.Description>
                    </Dialog.Header>
                    <div class="mt-4">
                        <RuntimeVersionPicker
                            runtime={runtime_choice}
                            value={runtime_choice.pin}
                            error={errors.runtime_version}
                        />
                    </div>
                    <Dialog.Footer>
                        <Button variant="ghost" type="button" onclick={() => (dialog = null)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Save</Button>
                    </Dialog.Footer>
                {/snippet}
            </Form>
        </Dialog.Content>
    </Dialog.Root>
{/if}

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
