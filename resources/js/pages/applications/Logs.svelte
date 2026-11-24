<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import { ArrowDown, Search, SlidersHorizontal } from '@lucide/svelte'
    import { onDestroy, onMount, untrack } from 'svelte'
    import { MediaQuery, SvelteSet } from 'svelte/reactivity'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { NativeSelect } from '@/components/ui/native-select'
    import * as Sheet from '@/components/ui/sheet'
    import type { StateTone } from '@/lib/fleet'
    import { toneText } from '@/lib/fleet'
    import { cn } from '@/lib/utils'
    import { REFRESH_HEADERS } from '@/lib/visits'

    type Source = { key: string; label: string; count: number }
    type Process = {
        process: string
        state: { key: string; label: string; tone: StateTone }
        memory: string | null
        restarts: number
        since: string | null
    }
    type Request = {
        method: string
        path: string
        status: number
        ms: number
        bytes: number
        ip: string | null
        agent: string | null
        referer: string | null
        host: string
        proto: string | null
    }
    type Entry = {
        id: string
        at: number | null
        source: string
        process: string | null
        level: 'info' | 'warning' | 'error'
        text: string
        more: string[]
        request: Request | null
        href: string | null
    }
    type Stats = {
        requests: number
        per_minute: number | null
        server_errors: number
        error_rate: number | null
        p50: number | null
        p95: number | null
        slowest: Array<{ path: string; ms: number; count: number }>
    }
    type Filters = { window: string; q: string; sources: string[]; level: string; around: number | null }
    type Lines = {
        pending: boolean
        error: string | null
        entries: Entry[]
        capped: boolean
        sources: Source[]
        stats: Stats | null
        processes: Process[]
    }

    let {
        server,
        application,
        sources: initialSources,
        processes: initialProcesses,
        windows,
        filters
    }: {
        server: { id: string; name: string }
        application: { id: string; name: string; href: string; lines_href: string }
        sources: Source[]
        processes: Process[]
        windows: Array<{ key: string; label: string }>
        filters: Filters
    } = $props()

    const FOLLOW_MS = 2000
    const PENDING_MS = 1000
    const SEARCH_MS = 350
    const LEVELS = [
        { key: 'all', label: 'Everything' },
        { key: 'warnings', label: 'Warnings and errors' },
        { key: 'errors', label: 'Errors' }
    ]

    let span = $state(untrack(() => filters.window))
    let query = $state(untrack(() => filters.q))
    let level = $state(untrack(() => filters.level))
    let chosen = $state<string[]>(untrack(() => [...filters.sources]))
    let around = $state<number | null>(untrack(() => filters.around))
    let entries = $state<Entry[]>([])
    let sources = $state<Source[]>(untrack(() => initialSources))
    let processes = $state<Process[]>(untrack(() => initialProcesses))
    let stats = $state<Stats | null>(null)
    let pending = $state(true)
    let error = $state<string | null>(null)
    let capped = $state(false)
    let halted = $state(false)
    let selected = $state<Entry | null>(null)
    let filtersOpen = $state(false)
    let following = $state(true)
    let unseen = $state(0)
    let copied = $state(false)
    let timeline = $state<HTMLDivElement | null>(null)

    const phone = new MediaQuery('max-width: 639px')
    const expanded = new SvelteSet<string>()
    const arriving = new SvelteSet<string>()
    const seen = new SvelteSet<string>()

    let timer: ReturnType<typeof setTimeout> | null = null
    let searchTimer: ReturnType<typeof setTimeout> | null = null
    let copyTimer: ReturnType<typeof setTimeout> | null = null
    let controller: AbortController | null = null

    const searching = $derived(query.trim() !== '')
    const live = $derived(!halted && !searching && around === null)
    const spanLabel = $derived(windows.find((option) => option.key === span)?.label ?? 'Last hour')
    const chosenAll = $derived(chosen.length === 0)
    const related = $derived(
        selected?.at == null
            ? null
            : entries.find(
                  (entry) =>
                      entry.id !== selected?.id &&
                      entry.request === null &&
                      entry.level === 'error' &&
                      entry.at !== null &&
                      Math.abs(entry.at - (selected?.at ?? 0)) <= 1000
              )
    )
    const statusText = $derived(
        around !== null
            ? 'Showing a moment'
            : searching
              ? 'Paused while you search'
              : halted
                ? 'Paused'
                : pending
                  ? 'Reading the logs'
                  : 'Live, following'
    )

    function params(): URLSearchParams {
        const search = new URLSearchParams()

        if (span !== '1h') {
            search.set('window', span)
        }

        if (searching) {
            search.set('q', query.trim())
        }

        if (level !== 'all') {
            search.set('level', level)
        }

        for (const source of chosen) {
            search.append('sources[]', source)
        }

        if (around !== null) {
            search.set('around', String(around))
        }

        return search
    }

    function remember() {
        const address = new URL(window.location.href)

        address.search = params().toString()
        window.history.replaceState(window.history.state, '', address)
    }

    function schedule(ms: number) {
        if (timer) {
            clearTimeout(timer)
        }

        if (!live && !pending) {
            return
        }

        timer = setTimeout(() => {
            if (document.visibilityState === 'visible') {
                void load(false)
            } else {
                schedule(ms)
            }
        }, ms)
    }

    async function load(reset: boolean) {
        controller?.abort()
        controller = new AbortController()

        const address = new URL(application.lines_href, window.location.href)

        address.search = params().toString()

        try {
            const response = await fetch(address, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...REFRESH_HEADERS },
                signal: controller.signal
            })

            if (response.status === 429) {
                schedule(10_000)

                return
            }

            apply((await response.json()) as Lines, reset)
            schedule(pending ? PENDING_MS : FOLLOW_MS)
        } catch (failure) {
            if (failure instanceof DOMException && failure.name === 'AbortError') {
                return
            }

            error = "Couldn't load the logs. Coresmith tries again in a moment."
            schedule(FOLLOW_MS * 2)
        }
    }

    function apply(body: Lines, reset: boolean) {
        pending = body.pending
        error = body.error
        sources = body.sources
        processes = body.processes
        stats = body.stats ?? stats

        if (body.pending) {
            return
        }

        capped = body.capped

        const newcomers = reset ? [] : body.entries.filter((entry) => !seen.has(entry.id))

        if (reset) {
            seen.clear()
        }

        for (const entry of body.entries) {
            seen.add(entry.id)
        }

        arriving.clear()

        for (const entry of newcomers.slice(-40)) {
            arriving.add(entry.id)
        }

        entries = body.entries

        if (!following && newcomers.length > 0) {
            unseen += newcomers.length
        }
    }

    function refilter() {
        remember()
        pending = true
        entries = []
        void load(true)
    }

    function chooseSpan(value: string) {
        span = value
        around = null
        refilter()
    }

    function chooseLevel(value: string) {
        level = value
        refilter()
    }

    function toggleSource(key: string) {
        const all = sources.map((source) => source.key)
        const current = chosenAll ? all : chosen
        const next = current.includes(key) ? current.filter((source) => source !== key) : [...current, key]

        chosen = next.length === all.length || next.length === 0 ? [] : next
        refilter()
    }

    function typed() {
        if (searchTimer) {
            clearTimeout(searchTimer)
        }

        searchTimer = setTimeout(refilter, SEARCH_MS)
    }

    function showAround(entry: Entry) {
        if (entry.at === null) {
            return
        }

        around = Math.floor(entry.at / 1000)
        selected = null
        refilter()
    }

    function backToLive() {
        around = null
        halted = false
        refilter()
    }

    function togglePause() {
        halted = !halted

        if (!halted) {
            void load(false)
        }
    }

    function scrolled() {
        if (!timeline) {
            return
        }

        following = timeline.scrollHeight - timeline.scrollTop - timeline.clientHeight < 24

        if (following) {
            unseen = 0
        }
    }

    function jumpToEnd() {
        if (!timeline) {
            return
        }

        following = true
        unseen = 0
        timeline.scrollTop = timeline.scrollHeight
        timeline.focus({ preventScroll: true })
    }

    async function copyLine(entry: Entry) {
        try {
            await navigator.clipboard.writeText(`${time(entry.at, true)} ${entry.text}`)
            copied = true

            if (copyTimer) {
                clearTimeout(copyTimer)
            }

            copyTimer = setTimeout(() => (copied = false), 2000)
        } catch {
            copied = false
        }
    }

    function time(at: number | null, precise = false): string {
        if (at === null) {
            return '—'
        }

        const moment = new Date(at)
        const clock = moment.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        })

        return precise ? `${clock}.${String(moment.getMilliseconds()).padStart(3, '0')}` : clock
    }

    function tag(entry: Entry): string {
        if (entry.process) {
            return entry.process
        }

        return (
            { requests: 'req', php: 'php', framework: 'laravel', scheduler: 'sched', deploys: 'deploy' }[
                entry.source
            ] ?? entry.source
        )
    }

    function duration(ms: number | null): string {
        if (ms === null) {
            return '—'
        }

        return ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`
    }

    function size(bytes: number): string {
        return bytes >= 1_048_576 ? `${(bytes / 1_048_576).toFixed(1)} MB` : `${(bytes / 1024).toFixed(1)} kB`
    }

    function statusTone(status: number): string {
        return status >= 500 ? 'log-status-error' : status >= 400 ? 'log-status-warn' : 'log-status-ok'
    }

    function statusMeaning(status: number): string {
        if (status >= 500) {
            return 'server error'
        }

        if (status === 404) {
            return 'not found'
        }

        if (status === 401 || status === 403) {
            return 'refused'
        }

        if (status === 429) {
            return 'too many requests'
        }

        if (status >= 400) {
            return 'the request was refused'
        }

        return status >= 300 ? 'redirect' : 'OK'
    }

    function referrer(request: Request): string {
        if (!request.referer) {
            return 'Nowhere, typed or bookmarked'
        }

        try {
            const from = new URL(request.referer)

            return from.host === request.host ? `${from.pathname}${from.search}` : request.referer
        } catch {
            return request.referer
        }
    }

    function browser(agent: string | null): string {
        if (!agent) {
            return 'Not sent'
        }

        if (/bot|crawl|spider|slurp/i.test(agent)) {
            return 'A crawler'
        }

        const name = /Edg\/(\d+)/.exec(agent)
            ? `Edge ${/Edg\/(\d+)/.exec(agent)?.[1]}`
            : /Firefox\/(\d+)/.exec(agent)
              ? `Firefox ${/Firefox\/(\d+)/.exec(agent)?.[1]}`
              : /Chrome\/(\d+)/.exec(agent)
                ? `Chrome ${/Chrome\/(\d+)/.exec(agent)?.[1]}`
                : /Version\/(\d+).*Safari/.exec(agent)
                  ? `Safari ${/Version\/(\d+)/.exec(agent)?.[1]}`
                  : /^curl\//.test(agent)
                    ? 'curl'
                    : null
        const system = /iPhone|iPad/.test(agent)
            ? 'iOS'
            : /Android/.test(agent)
              ? 'Android'
              : /Mac OS X|Macintosh/.test(agent)
                ? 'macOS'
                : /Windows/.test(agent)
                  ? 'Windows'
                  : /Linux/.test(agent)
                    ? 'Linux'
                    : null

        if (name === null) {
            return agent.slice(0, 80)
        }

        return system === null ? name : `${name} on ${system}`
    }

    function rate(stats: Stats): string {
        if (stats.requests === 0 || stats.per_minute === null) {
            return 'No requests yet'
        }

        return stats.per_minute < 1 ? 'Fewer than one a minute' : `About ${Math.round(stats.per_minute)} a minute`
    }

    $effect(() => {
        void entries

        if (timeline && untrack(() => following)) {
            timeline.scrollTop = timeline.scrollHeight
        }
    })

    $effect(() => {
        if (live) {
            untrack(() => schedule(FOLLOW_MS))
        }
    })

    onMount(() => {
        void load(true)
    })

    onDestroy(() => {
        controller?.abort()

        for (const pendingTimer of [timer, searchTimer, copyTimer]) {
            if (pendingTimer) {
                clearTimeout(pendingTimer)
            }
        }
    })
</script>

<AppHead title="Logs · {application.name}" />

<BackLink href={application.href} label={application.name} />

{#snippet filterControls(stacked: boolean)}
    <label
        class={cn(
            'inline-flex items-center gap-2 text-[12.5px] text-muted-foreground',
            stacked && 'flex-col items-stretch'
        )}
    >
        Window
        <NativeSelect
            value={span}
            options={windows.map((option) => ({ value: option.key, label: option.label }))}
            class="h-9 max-sm:h-11"
            onchange={(event) => chooseSpan((event.currentTarget as HTMLSelectElement).value)}
            data-testid="logs-window"
        />
    </label>
    <div
        role="group"
        aria-label="Level"
        class={cn('inline-flex overflow-hidden rounded-md border border-border', stacked && 'flex')}
    >
        {#each LEVELS as option, index (option.key)}
            <button
                type="button"
                aria-pressed={level === option.key}
                class={cn(
                    'focus-ring h-9 px-3 text-[12.5px] font-medium whitespace-nowrap text-muted-foreground aria-pressed:bg-muted aria-pressed:text-foreground max-sm:h-11',
                    index > 0 && 'border-l border-border'
                )}
                onclick={() => chooseLevel(option.key)}>{option.label}</button
            >
        {/each}
    </div>
    <div role="group" aria-label="Sources" class="flex flex-wrap gap-2" data-testid="logs-sources">
        {#each sources as source (source.key)}
            <button
                type="button"
                aria-pressed={chosenAll || chosen.includes(source.key)}
                class={cn(
                    'focus-ring inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-3 text-[12.5px] font-medium text-muted-foreground aria-pressed:border-ring/40 aria-pressed:bg-muted aria-pressed:text-foreground max-sm:h-11',
                    source.key.startsWith('process:') && 'font-mono'
                )}
                onclick={() => toggleSource(source.key)}
                >{source.label}<span class="font-mono text-[11px] text-muted-foreground">{source.count}</span></button
            >
        {/each}
    </div>
{/snippet}

{#snippet details(entry: Entry)}
    {#if entry.request}
        <div class="flex flex-col gap-3" data-testid="logs-request">
            <div>
                <p class="type-label m-0 text-muted-foreground">Request</p>
                <p class="m-0 mt-1 font-mono text-[14px] font-medium wrap-anywhere">
                    {entry.request.method}
                    {entry.request.path}
                </p>
                <p class="m-0 mt-1 text-[13px]">
                    <span
                        class={cn(
                            'font-mono',
                            toneText(
                                entry.request.status >= 500
                                    ? 'destructive'
                                    : entry.request.status >= 400
                                      ? 'warning'
                                      : 'success'
                            )
                        )}>{entry.request.status}</span
                    >, {statusMeaning(entry.request.status)} · {duration(entry.request.ms)}
                </p>
            </div>
            <dl class="m-0 grid grid-cols-[5.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-[13px]">
                <dt class="text-muted-foreground">Time</dt>
                <dd class="m-0 font-mono">{time(entry.at, true)}</dd>
                <dt class="text-muted-foreground">Host</dt>
                <dd class="m-0 font-mono wrap-anywhere">{entry.request.host}</dd>
                <dt class="text-muted-foreground">From</dt>
                <dd class="m-0 font-mono">{entry.request.ip ?? 'Not recorded'}</dd>
                <dt class="text-muted-foreground">Came from</dt>
                <dd class="m-0 font-mono wrap-anywhere">{referrer(entry.request)}</dd>
                <dt class="text-muted-foreground">Browser</dt>
                <dd class="m-0">{browser(entry.request.agent)}</dd>
                <dt class="text-muted-foreground">Sent back</dt>
                <dd class="m-0">
                    {size(entry.request.bytes)}{entry.request.proto
                        ? ` over ${entry.request.proto.replace('.0', '')}`
                        : ''}
                </dd>
            </dl>
            {#if related}
                <p class="m-0 text-[12.5px] text-muted-foreground" data-testid="logs-related">
                    {related.source === 'php' ? 'A PHP error' : 'An error'} was logged in the same second.
                </p>
            {/if}
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" onclick={() => showAround(entry)} data-testid="logs-around"
                    >Show logs around this</Button
                >
                <Button variant="outline" size="sm" onclick={() => copyLine(entry)}
                    >{copied ? 'Copied' : 'Copy line'}</Button
                >
            </div>
        </div>
    {/if}
{/snippet}

<div class="mb-5 flex flex-wrap items-end justify-between gap-4 max-sm:mb-4">
    <div class="min-w-0">
        <p class="m-0 font-mono text-[12.5px] text-muted-foreground">{application.name} on {server.name}</p>
        <h1 class="type-h1 m-0 mt-1.5 max-sm:text-[24px]">Logs</h1>
        <p class="m-0 mt-1.5 text-[14px] text-muted-foreground max-sm:hidden">
            Requests, process output and errors, read from {server.name} as they happen.
        </p>
    </div>
    <div class="flex items-center gap-2.5">
        <span
            role="status"
            class="inline-flex h-9 items-center gap-2 rounded-md border border-border px-3 text-[13px] max-sm:h-11"
            data-testid="logs-status"
        >
            <StateDot
                tone={live && !pending ? 'success' : 'default'}
                state={live && !pending ? 'live' : ''}
                size="sm"
            />{statusText}
        </span>
        {#if around !== null}
            <Button variant="outline" size="sm" onclick={backToLive}>Back to live</Button>
        {:else if !searching}
            <Button variant="outline" size="sm" onclick={togglePause} data-testid="logs-pause"
                >{halted ? 'Resume' : 'Pause'}</Button
            >
        {/if}
    </div>
</div>

<section aria-label="At a glance" class="mb-3 grid grid-cols-4 gap-3 max-lg:grid-cols-2" data-testid="logs-glance">
    <div
        class="flex min-w-0 flex-col gap-1.5 rounded-xl border border-border bg-card px-4.5 py-4 max-sm:px-3.5 max-sm:py-3"
    >
        <span class="text-[12px] font-medium text-muted-foreground">Requests, {spanLabel.toLowerCase()}</span>
        <span class="font-mono text-[22px] font-medium max-sm:text-[19px]"
            >{stats ? stats.requests.toLocaleString() : '—'}</span
        >
        <span class="text-[12px] text-muted-foreground max-sm:hidden">{stats ? rate(stats) : 'Reading'}</span>
    </div>
    <div
        class="flex min-w-0 flex-col gap-1.5 rounded-xl border border-border bg-card px-4.5 py-4 max-sm:px-3.5 max-sm:py-3"
    >
        <span class="text-[12px] font-medium text-muted-foreground">Server errors</span>
        <span
            class={cn(
                'font-mono text-[22px] font-medium max-sm:text-[19px]',
                stats && stats.server_errors > 0 && 'text-destructive-strong'
            )}>{stats?.error_rate == null ? '—' : `${stats.error_rate}%`}</span
        >
        <span class="text-[12px] text-muted-foreground max-sm:hidden"
            >{stats
                ? `${stats.server_errors.toLocaleString()} of ${stats.requests.toLocaleString()} answered 5xx`
                : 'Reading'}</span
        >
    </div>
    <div
        class="flex min-w-0 flex-col gap-1.5 rounded-xl border border-border bg-card px-4.5 py-4 max-sm:px-3.5 max-sm:py-3"
    >
        <span class="text-[12px] font-medium text-muted-foreground">Response time, p95</span>
        <span class="font-mono text-[22px] font-medium max-sm:text-[19px]">{duration(stats?.p95 ?? null)}</span>
        <span class="text-[12px] text-muted-foreground max-sm:hidden"
            >{stats?.p50 == null ? 'Reading' : `Half answer within ${duration(stats.p50)}`}</span
        >
    </div>
    <div
        class="flex min-w-0 flex-col gap-1.5 rounded-xl border border-border bg-card px-4.5 py-4 max-sm:px-3.5 max-sm:py-3"
    >
        <span class="text-[12px] font-medium text-muted-foreground"
            >{stats && stats.slowest.length === 1 ? 'Slowest path' : 'Slowest paths'}</span
        >
        {#if stats && stats.slowest.length > 0}
            <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-0.5 font-mono text-[12.5px]">
                {#each stats.slowest as path, index (path.path)}
                    <span class={cn('truncate', index > 0 && 'max-sm:hidden')} title={path.path}
                        >{path.path.replace(/^[A-Z]+ /, '')}</span
                    >
                    <span class={cn(path.ms >= 1000 && 'text-warning-strong', index > 0 && 'max-sm:hidden')}
                        >{duration(path.ms)}</span
                    >
                {/each}
            </div>
        {:else}
            <span class="font-mono text-[22px] font-medium max-sm:text-[19px]">—</span>
        {/if}
    </div>
</section>

{#if processes.length > 0}
    <section
        aria-label="Processes"
        class="mb-5 flex flex-wrap items-center gap-x-7 gap-y-2 rounded-xl border border-border bg-card px-4.5 py-3.5 max-sm:mb-4 max-sm:flex-col max-sm:items-start max-sm:px-3.5"
        data-testid="logs-processes"
    >
        <span class="text-[12px] font-medium text-muted-foreground">Processes</span>
        {#each processes as process (process.process)}
            <div class="flex min-w-0 items-center gap-2.5">
                <StateDot tone={process.state.tone} size="sm" />
                <span class="font-mono text-[13px]">{process.process}</span>
                <span class="text-[12.5px] text-muted-foreground"
                    >{process.state.label}{process.state.key === 'running' && process.since
                        ? ` since ${process.since}`
                        : ''}{process.memory ? `, ${process.memory}` : ''}</span
                >
            </div>
        {/each}
    </section>
{/if}

<div class="mb-3 flex flex-wrap items-center gap-2.5">
    <div class="relative min-w-0 flex-1 basis-80 sm:max-w-115">
        <Search
            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <Input
            type="search"
            bind:value={query}
            oninput={typed}
            placeholder="Search requests and output"
            aria-label="Search logs"
            class="h-9 pl-9 max-sm:h-11"
            data-testid="logs-search"
        />
    </div>
    <div class="contents max-sm:hidden">
        {@render filterControls(false)}
    </div>
    <Button variant="outline" class="sm:hidden" onclick={() => (filtersOpen = true)} data-testid="logs-filters">
        <SlidersHorizontal aria-hidden="true" />Filters
    </Button>
</div>

{#if around !== null}
    <p class="mb-3 text-[13px] text-muted-foreground" data-testid="logs-around-note">
        Showing ten seconds either side of {time(around * 1000)}.
    </p>
{:else if capped}
    <p class="mb-3 text-[13px] text-muted-foreground" data-testid="logs-capped">
        Showing the newest lines only. Pick a shorter window or search to see older ones.
    </p>
{/if}

{#if error}
    <p class="tint-warning mb-3 rounded-lg border px-3.5 py-2.5 text-[13px]" role="alert" data-testid="logs-error">
        {error}
    </p>
{/if}

<div class="flex flex-wrap items-start gap-4">
    <div class="relative min-w-0 flex-[999_1_40rem]">
        <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
        <div
            bind:this={timeline}
            class="terminal relative h-110 px-2 py-2.5 text-[12.5px] leading-[1.55] max-sm:h-120 max-sm:px-1 max-sm:text-[11.5px]"
            role="log"
            aria-live="off"
            aria-label="Logs for {application.name}"
            tabindex="0"
            onscroll={scrolled}
            data-testid="logs-timeline"
        >
            {#each entries as entry, index (entry.id)}
                <div
                    class={cn(
                        'log-row',
                        `log-row-${entry.level}`,
                        arriving.has(entry.id) && 'log-line-new',
                        selected?.id === entry.id && 'log-row-selected'
                    )}
                    style={arriving.has(entry.id) ? `animation-delay: ${Math.min(index % 40, 20) * 18}ms` : undefined}
                    data-source={entry.source}
                    data-level={entry.level}
                >
                    <span class="log-time" aria-label={entry.at === null ? 'No time on this line' : undefined}
                        >{time(entry.at)}</span
                    >
                    <span class="log-tag" title={tag(entry)}>{tag(entry)}</span>
                    <div class="min-w-0">
                        {#if entry.request}
                            <button
                                type="button"
                                class="focus-ring w-full cursor-pointer rounded-sm text-left wrap-anywhere"
                                aria-pressed={selected?.id === entry.id}
                                onclick={() => (selected = selected?.id === entry.id ? null : entry)}
                            >
                                <span class="font-medium">{entry.request.method}</span>
                                {entry.request.path}&nbsp;&nbsp;<span class={statusTone(entry.request.status)}
                                    >{entry.request.status}</span
                                >&nbsp;&nbsp;<span class={entry.request.ms >= 500 ? 'log-status-warn' : 'log-time'}
                                    >{duration(entry.request.ms)}</span
                                ><span class="log-time max-sm:hidden">{` · ${size(entry.request.bytes)}`}</span>
                            </button>
                        {:else if entry.href}
                            <Link href={entry.href} class="log-link wrap-anywhere">{entry.text}</Link>
                        {:else}
                            <span class="log-text wrap-anywhere whitespace-pre-wrap">{entry.text}</span>
                            {#if entry.more.length > 0}
                                <button
                                    type="button"
                                    class="focus-ring ml-1.5 cursor-pointer rounded-sm text-[11.5px] text-[oklch(0.72_0.01_80)] underline-offset-2 hover:underline"
                                    aria-expanded={expanded.has(entry.id)}
                                    onclick={() =>
                                        expanded.has(entry.id) ? expanded.delete(entry.id) : expanded.add(entry.id)}
                                    >{expanded.has(entry.id)
                                        ? 'Show less'
                                        : `${entry.more.length} more ${entry.more.length === 1 ? 'line' : 'lines'}`}</button
                                >
                                {#if expanded.has(entry.id)}
                                    <pre
                                        class="m-0 mt-1 font-mono text-[11.5px] wrap-anywhere whitespace-pre-wrap text-[oklch(0.76_0.01_80)]">{entry.more.join(
                                            '\n'
                                        )}</pre>
                                {/if}
                            {/if}
                        {/if}
                    </div>
                </div>
            {:else}
                <div class="px-2.5 py-1 text-[oklch(0.72_0.01_80)]" data-testid="logs-empty">
                    {#if pending}
                        Reading the logs from {server.name}…
                    {:else if searching}
                        Nothing matches “{query.trim()}” in {spanLabel.toLowerCase()}.
                    {:else}
                        Nothing logged in {spanLabel.toLowerCase()}.
                        {#if span !== '24h' && span !== '7d' && span !== 'all'}
                            <button
                                type="button"
                                class="focus-ring cursor-pointer rounded-sm text-[oklch(0.84_0.14_45)] underline underline-offset-2"
                                onclick={() => chooseSpan('24h')}>Look at the last 24 hours</button
                            >
                        {/if}
                    {/if}
                </div>
            {/each}
            {#if live && !pending && entries.length > 0}
                <div class="log-cursor px-2.5" aria-hidden="true">▍</div>
            {/if}
        </div>
        {#if unseen > 0 && !following}
            <button
                type="button"
                class="log-pill focus-ring absolute bottom-3.5 left-1/2 inline-flex h-9 -translate-x-1/2 items-center gap-1.5 rounded-md border px-3.5 font-sans text-[12.5px] font-medium shadow-md max-sm:h-11"
                onclick={jumpToEnd}
                data-testid="logs-new"
            >
                <ArrowDown class="size-3.5" aria-hidden="true" />{unseen === 1 ? '1 new line' : `${unseen} new lines`}
            </button>
        {/if}
    </div>

    {#if selected?.request}
        <aside
            class="w-80 flex-[1_1_18rem] rounded-xl border border-border bg-card px-4.5 py-4 max-sm:hidden"
            aria-label="Request details"
        >
            {@render details(selected)}
        </aside>
    {/if}
</div>

<Sheet.Root open={filtersOpen} onOpenChange={(open) => (filtersOpen = open)}>
    <Sheet.Content side="bottom" class="gap-4 px-4.5 pt-5 pb-6">
        <Sheet.Title>Filters</Sheet.Title>
        {@render filterControls(true)}
    </Sheet.Content>
</Sheet.Root>

<Sheet.Root
    open={phone.current && selected?.request != null && !filtersOpen}
    onOpenChange={(open) => !open && (selected = null)}
>
    <Sheet.Content side="bottom" class="gap-3 px-4.5 pt-5 pb-6">
        <Sheet.Title class="sr-only">Request details</Sheet.Title>
        {#if selected}
            {@render details(selected)}
        {/if}
    </Sheet.Content>
</Sheet.Root>
