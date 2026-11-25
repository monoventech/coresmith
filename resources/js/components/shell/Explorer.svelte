<script lang="ts">
    import ExplorerRow from '@/components/shell/ExplorerRow.svelte'
    import PhaseSegments from '@/components/shell/PhaseSegments.svelte'
    import { Input } from '@/components/ui/input'
    import { elapsedSince, explorerState, hideExplorer, toggleProject } from '@/lib/explorer.svelte'
    import { appNote, explorerEntries, FILTER_LIMIT, filterEntries, highlight } from '@/lib/explorer-filter'
    import { getInitials } from '@/lib/initials'
    import type { ExplorerApp, ExplorerProps, ExplorerProject } from '@/lib/panel'
    import { isMac, pathOf, statusDot } from '@/lib/panel'
    import { ENGINES } from '@/lib/projects'
    import { cn } from '@/lib/utils'
    import { Link, page, router } from '@inertiajs/svelte'
    import {
        ChevronDown,
        ChevronRight,
        ChevronsUpDown,
        Database,
        Folder,
        ListFilter,
        PanelLeftClose,
        Plus,
        Search,
        Server
    } from '@lucide/svelte'

    let { explorer, onSearch, onAccount }: { explorer: ExplorerProps; onSearch: () => void; onAccount: () => void } =
        $props()

    const shell = explorerState()
    const shortcut = isMac() ? '⌘K' : 'Ctrl K'
    const path = $derived(pathOf(page.url))
    const empty = $derived(
        explorer.projects.length === 0 && explorer.unfiled.length === 0 && explorer.servers.length === 0
    )

    let now = $state(Date.now())

    $effect(() => {
        if (explorer.running === null) {
            return
        }

        const ticker = setInterval(() => (now = Date.now()), 1000)

        return () => clearInterval(ticker)
    })

    const elapsed = $derived(elapsedSince(explorer.running?.started_at ?? null, now))
    const opened = $derived({
        pinned: !shell.folded.includes('section:pinned'),
        attention: !shell.folded.includes('section:attention'),
        projects: !shell.folded.includes('section:projects'),
        apps: !shell.folded.includes('section:apps'),
        servers: !shell.folded.includes('section:servers')
    })

    const ATTENTION_SHOWN = 5

    let query = $state('')
    let field = $state<HTMLInputElement | null>(null)
    let lists = $state<HTMLElement | null>(null)
    let everyAttention = $state(false)
    let pinCount = -1

    const everyApp = $derived([...explorer.projects.flatMap((project) => project.apps), ...explorer.unfiled])
    const pinned = $derived(
        shell.pinned
            .map((id) => everyApp.find((app) => app.id === id))
            .filter((app): app is ExplorerApp => app !== undefined)
    )
    const filtering = $derived(query.trim() !== '')

    $effect(() => {
        const count = pinned.length

        if (pinCount >= 0 && count > pinCount && lists) {
            const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches

            lists.scrollTo({ top: 0, behavior: calm ? 'auto' : 'smooth' })
        }

        pinCount = count
    })
    const found = $derived(filtering ? filterEntries(explorerEntries(explorer), query) : [])
    const shown = $derived(found.slice(0, FILTER_LIMIT))
    const attention = $derived(everyAttention ? explorer.attention : explorer.attention.slice(0, ATTENTION_SHOWN))
    const status = $derived(
        found.length === 0
            ? `Nothing matches “${query.trim()}”.`
            : found.length === 1
              ? '1 match. Enter opens it.'
              : `${found.length} matches. Enter opens the first, ↓ moves down.`
    )

    function current(href: string): boolean {
        const target = pathOf(href)

        return path === target || path.startsWith(`${target}/`)
    }

    function serverCurrent(href: string): boolean {
        const target = pathOf(href)

        return (
            path === target ||
            (path.startsWith(`${target}/`) && !/^\/(applications|databases)\//.test(path.slice(target.length)))
        )
    }

    function apps(project: ExplorerProject): string {
        const count = project.apps.length

        return `${count} ${count === 1 ? 'app' : 'apps'}`
    }

    function noteClass(tone: string): string {
        switch (tone) {
            case 'accent':
                return 'text-primary-strong'
            case 'warning':
                return 'text-warning-strong'
            case 'destructive':
                return 'text-destructive-strong'
            default:
                return ''
        }
    }

    function sentence(phase: string): string {
        return phase.charAt(0).toUpperCase() + phase.slice(1)
    }

    function severityTone(severity: string): string {
        return severity === 'critical' ? 'destructive' : 'warning'
    }

    function results(): HTMLElement[] {
        return Array.from(document.querySelectorAll<HTMLElement>('[data-explorer-result]'))
    }

    function onFilterKey(event: KeyboardEvent) {
        if (event.key === 'Enter' && shown.length > 0) {
            event.preventDefault()
            router.visit(shown[0].href)
        } else if (event.key === 'ArrowDown' && shown.length > 0) {
            event.preventDefault()
            results()[0]?.focus()
        } else if (event.key === 'Escape' && query !== '') {
            event.preventDefault()
            event.stopPropagation()
            query = ''
        }
    }

    function onResultKey(event: KeyboardEvent) {
        const links = results()
        const at = links.indexOf(event.target as HTMLElement)

        if (event.key === 'ArrowDown' && at >= 0 && at < links.length - 1) {
            event.preventDefault()
            links[at + 1].focus()
        } else if (event.key === 'ArrowUp' && at > 0) {
            event.preventDefault()
            links[at - 1].focus()
        } else if (event.key === 'ArrowUp' || event.key === 'Escape') {
            event.preventDefault()
            event.stopPropagation()
            field?.focus()
        }
    }
</script>

{#snippet sectionToggle(key: string, label: string, open: boolean, count: number, tone = 'text-muted-foreground')}
    <button
        type="button"
        class={cn(
            'focus-ring -ml-1 inline-flex h-6 cursor-pointer items-center gap-1 rounded-md bg-transparent px-1 text-[11.5px] font-semibold tracking-[0.04em] hover:text-foreground pointer-coarse:h-11',
            tone
        )}
        aria-expanded={open}
        onclick={() => toggleProject(`section:${key}`)}
    >
        {#if open}
            <ChevronDown class="size-3" aria-hidden="true" />
        {:else}
            <ChevronRight class="size-3" aria-hidden="true" />
        {/if}
        <span id="explorer-{key}-label">{label}</span>
        <span class="ml-0.5 font-mono text-[11px] font-medium tabular-nums" data-testid="explorer-count">{count}</span>
    </button>
{/snippet}

<aside
    aria-label="Explorer"
    class="panel flex h-full w-72 shrink-0 flex-col gap-3 border-r border-border bg-card px-3 py-3.5"
    data-testid="explorer"
>
    <div class="flex items-center gap-1.5">
        <button
            type="button"
            class="focus-ring flex h-10 min-w-0 flex-1 cursor-pointer items-center gap-2.5 rounded-lg bg-transparent px-2 text-left transition-colors duration-(--dur-fast) hover:bg-muted pointer-coarse:h-11"
            onclick={onAccount}
        >
            <span
                class="inline-flex size-6.5 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-[11px] font-semibold text-muted-foreground"
                aria-hidden="true">{getInitials(explorer.team.name)}</span
            >
            <span class="flex min-w-0 flex-col leading-tight">
                <span class="truncate text-[14px] font-semibold">{explorer.team.name}</span>
                <span class="truncate text-[11.5px] text-muted-foreground">{explorer.team.detail}</span>
            </span>
            <ChevronsUpDown class="ml-auto size-4 shrink-0 text-muted-foreground" />
        </button>
        <button
            type="button"
            class="focus-ring inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted pointer-coarse:size-11"
            aria-label="Hide the explorer"
            title="Hide the explorer"
            onclick={hideExplorer}
        >
            <PanelLeftClose class="size-4" />
        </button>
    </div>

    <div class="flex shrink-0 items-center gap-1.5">
        <div class="relative min-w-0 flex-1">
            <label for="explorer-filter" class="sr-only">Filter apps and servers</label>
            <ListFilter
                class="pointer-events-none absolute top-1/2 left-2.5 size-3.75 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                id="explorer-filter"
                bind:ref={field}
                bind:value={query}
                type="search"
                autocomplete="off"
                spellcheck={false}
                placeholder="Filter apps and servers"
                aria-describedby={filtering ? 'explorer-filter-status' : undefined}
                aria-controls={filtering ? 'explorer-results' : undefined}
                class="h-9 pl-8 text-[13px] pointer-coarse:h-11 [&::-webkit-search-cancel-button]:appearance-none"
                onkeydown={onFilterKey}
                data-testid="explorer-filter"
            />
        </div>
        <button
            type="button"
            class="focus-ring inline-flex h-9 shrink-0 cursor-pointer items-center gap-1 rounded-md border border-border bg-background px-2 text-muted-foreground transition-colors duration-(--dur-fast) hover:text-foreground pointer-coarse:h-11 pointer-coarse:min-w-11"
            title="Search everything ({shortcut})"
            onclick={onSearch}
        >
            <Search class="size-3.75" aria-hidden="true" />
            <span class="sr-only">Search everything</span>
            <kbd class="font-mono text-[11px] pointer-coarse:hidden" aria-hidden="true">{shortcut}</kbd>
        </button>
    </div>

    {#if filtering}
        <div
            id="explorer-results"
            class="-mx-3 flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-3"
            data-testid="explorer-results"
        >
            <p id="explorer-filter-status" class="m-0 px-1.5 text-[12px] text-muted-foreground" aria-live="polite">
                {status}
            </p>
            {#if shown.length > 0}
                <ul class="m-0 flex list-none flex-col gap-px p-0">
                    {#each shown as entry, index (entry.key)}
                        {@const [before, match, after] = highlight(entry.name, query)}
                        <li>
                            <Link
                                href={entry.href}
                                data-explorer-result
                                aria-current={(
                                    entry.kind === 'server' ? serverCurrent(entry.href) : current(entry.href)
                                )
                                    ? 'page'
                                    : undefined}
                                class={cn(
                                    'focus-ring flex h-8 min-w-0 items-center gap-2 rounded-md px-2 text-foreground no-underline transition-colors duration-(--dur-fast) hover:bg-muted pointer-coarse:h-11',
                                    index === 0 && 'bg-accent'
                                )}
                                onkeydown={onResultKey}
                            >
                                {#if entry.kind === 'database'}
                                    <Database class="size-3.25 shrink-0 text-muted-foreground" aria-hidden="true" />
                                {:else if entry.kind === 'project'}
                                    <Folder class="size-3.25 shrink-0 text-muted-foreground" aria-hidden="true" />
                                {:else if entry.kind === 'server' && entry.tone === 'success'}
                                    <Server class="size-3.25 shrink-0 text-muted-foreground" aria-hidden="true" />
                                {:else}
                                    <span
                                        class={cn('size-2 shrink-0 rounded-full', statusDot(entry.tone))}
                                        aria-hidden="true"
                                    ></span>
                                {/if}
                                <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]"
                                    >{before}<mark class="rounded-xs bg-primary/30 text-inherit">{match}</mark
                                    >{after}</span
                                >
                                <span class="shrink-0 truncate text-[11px] text-muted-foreground">{entry.note}</span>
                            </Link>
                        </li>
                    {/each}
                </ul>
            {/if}
            {#if found.length > shown.length}
                <p class="m-0 px-1.5 text-[12px] text-muted-foreground">
                    Showing the first {FILTER_LIMIT}. Type more of the name to narrow it down.
                </p>
            {/if}
            <p class="m-0 mt-auto border-t border-border px-1.5 pt-3 text-[12.5px] text-muted-foreground">
                Looking for something else?
                <button
                    type="button"
                    class="focus-ring cursor-pointer rounded-sm bg-transparent p-0 font-medium text-primary-strong pointer-coarse:min-h-11"
                    onclick={onSearch}>Search everything</button
                >
            </p>
        </div>
    {/if}

    <div
        bind:this={lists}
        class={cn('-mx-3 flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-3', filtering && 'hidden')}
        data-testid="explorer-lists"
    >
        {#if pinned.length > 0}
            <div
                class="flex flex-col gap-px"
                role="group"
                aria-labelledby="explorer-pinned-label"
                data-testid="explorer-pinned"
            >
                <div class="flex h-7 items-center px-2 pointer-coarse:h-11">
                    {@render sectionToggle('pinned', 'Pinned', opened.pinned, pinned.length)}
                </div>
                {#each opened.pinned ? pinned : [] as app (app.id)}
                    <ExplorerRow
                        href={app.href}
                        name={app.name}
                        detail="{app.state.label} on {app.server}"
                        tone={app.state.tone}
                        tags={app.tags}
                        state={appNote(app)}
                        asideClass={noteClass(app.state.tone)}
                        current={current(app.href)}
                    />
                {/each}
            </div>
        {/if}

        {#if explorer.attention.length > 0}
            <div
                class="flex flex-col gap-px"
                role="group"
                aria-labelledby="explorer-attention-label"
                data-testid="explorer-attention"
            >
                <div class="flex h-7 items-center px-2 pointer-coarse:h-11">
                    {@render sectionToggle(
                        'attention',
                        'Needs attention',
                        opened.attention,
                        explorer.attention.length,
                        'text-warning-strong'
                    )}
                </div>
                {#each opened.attention ? attention : [] as item, index (`${index}:${item.href}`)}
                    <ExplorerRow
                        href={item.href}
                        name={item.subject}
                        detail={item.summary}
                        tone={severityTone(item.severity)}
                        aside={item.summary}
                        asideClass={noteClass(severityTone(item.severity))}
                        current={false}
                    />
                {/each}
                {#if opened.attention && explorer.attention.length > ATTENTION_SHOWN}
                    <button
                        type="button"
                        class="focus-ring flex h-7 cursor-pointer items-center rounded-md bg-transparent pl-3.5 text-left text-[12px] font-medium text-muted-foreground hover:text-foreground pointer-coarse:h-11"
                        aria-expanded={everyAttention}
                        onclick={() => (everyAttention = !everyAttention)}
                        >{everyAttention
                            ? 'Show fewer'
                            : `Show ${explorer.attention.length - ATTENTION_SHOWN} more`}</button
                    >
                {/if}
            </div>
        {/if}

        {#if empty}
            <div class="flex flex-col gap-2 px-2 py-4 text-[13px] text-muted-foreground">
                <p class="m-0">No servers yet.</p>
                {#if explorer.can.createServer}
                    <Link
                        href={route('servers.create')}
                        class="focus-ring w-fit rounded-sm font-medium text-primary-strong">Provision a server</Link
                    >
                {/if}
            </div>
        {/if}

        {#if explorer.projects.length > 0}
            <div class="flex flex-col gap-0.5" role="group" aria-labelledby="explorer-projects-label">
                <div class="flex h-7 items-center justify-between px-2 pointer-coarse:h-11">
                    {@render sectionToggle('projects', 'Projects', opened.projects, explorer.projects.length)}
                    {#if explorer.can.createProject}
                        <Link
                            href={route('projects.index', { create: 1 })}
                            class="focus-ring inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted pointer-coarse:size-11"
                            aria-label="Create a project"
                            title="Create a project"><Plus class="size-3.5" /></Link
                        >
                    {/if}
                </div>
                {#each opened.projects ? explorer.projects : [] as project (project.id)}
                    {@const open = !shell.folded.includes(project.id)}
                    <div class="flex flex-col gap-px" data-testid="explorer-project">
                        <div class="flex h-8 items-center gap-1.5 pr-2 pointer-coarse:h-11">
                            <button
                                type="button"
                                class="focus-ring inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground hover:bg-muted pointer-coarse:size-11"
                                aria-label="What's in {project.name}"
                                aria-expanded={open}
                                aria-controls="explorer-project-{project.id}"
                                onclick={() => toggleProject(project.id)}
                            >
                                {#if open}
                                    <ChevronDown class="size-3.5" />
                                {:else}
                                    <ChevronRight class="size-3.5" />
                                {/if}
                            </button>
                            <Folder class="size-3.75 shrink-0 text-muted-foreground" />
                            <Link
                                href={project.href}
                                aria-current={path === pathOf(project.href) ? 'page' : undefined}
                                class="focus-ring min-w-0 flex-1 truncate rounded-sm text-[13.5px] font-medium text-foreground no-underline pointer-coarse:leading-11"
                                >{project.name}</Link
                            >
                            {#if project.deploying}
                                <span
                                    class="status-dot-working size-1.75 shrink-0 rounded-full bg-primary"
                                    aria-hidden="true"
                                ></span>
                                <span class="sr-only">deploying</span>
                            {:else}
                                <span class="shrink-0 text-[11.5px] text-muted-foreground">{apps(project)}</span>
                            {/if}
                        </div>
                        {#if open}
                            <div id="explorer-project-{project.id}" class="flex flex-col gap-px">
                                {#each project.apps as app (app.id)}
                                    <ExplorerRow
                                        href={app.href}
                                        name={app.name}
                                        detail="{app.state.label} on {app.server}"
                                        tone={app.state.tone}
                                        tags={app.tags}
                                        state={appNote(app)}
                                        asideClass={noteClass(app.state.tone)}
                                        indent
                                        current={current(app.href)}
                                    />
                                {/each}
                                {#each project.databases as database (database.id)}
                                    <ExplorerRow
                                        href={database.href}
                                        name={database.name}
                                        detail={ENGINES[database.engine] ?? database.engine}
                                        database
                                        indent
                                        current={current(database.href)}
                                    />
                                {/each}
                                {#if project.apps.length === 0 && project.databases.length === 0}
                                    <p class="m-0 flex h-7 items-center pl-9.5 text-[12px] text-muted-foreground">
                                        Nothing here yet
                                    </p>
                                {/if}
                            </div>
                        {/if}
                    </div>
                {/each}
            </div>
        {/if}

        {#if explorer.unfiled.length > 0}
            <div
                class="flex flex-col gap-px"
                role="group"
                aria-labelledby="explorer-apps-label"
                data-testid="explorer-apps"
            >
                <div class="flex h-7 items-center justify-between px-2 pointer-coarse:h-11">
                    {@render sectionToggle('apps', 'Apps', opened.apps, explorer.unfiled.length)}
                    {#if explorer.can.createApplication}
                        <Link
                            href={route('applications.new')}
                            class="focus-ring inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted pointer-coarse:size-11"
                            aria-label="Add an app"
                            title="Add an app"><Plus class="size-3.5" /></Link
                        >
                    {/if}
                </div>
                {#each opened.apps ? explorer.unfiled : [] as app (app.id)}
                    <ExplorerRow
                        href={app.href}
                        name={app.name}
                        detail="{app.state.label} on {app.server}"
                        tone={app.state.tone}
                        tags={app.tags}
                        state={appNote(app)}
                        asideClass={noteClass(app.state.tone)}
                        current={current(app.href)}
                    />
                {/each}
            </div>
        {/if}

        {#if explorer.servers.length > 0}
            <div
                class="flex flex-col gap-px"
                role="group"
                aria-labelledby="explorer-servers-label"
                data-testid="explorer-servers"
            >
                <div class="flex h-7 items-center justify-between px-2 pointer-coarse:h-11">
                    {@render sectionToggle('servers', 'Servers', opened.servers, explorer.servers.length)}
                    {#if explorer.can.createServer}
                        <Link
                            href={route('servers.create')}
                            class="focus-ring inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted pointer-coarse:size-11"
                            aria-label="Add a server"
                            title="Add a server"><Plus class="size-3.5" /></Link
                        >
                    {/if}
                </div>
                {#each opened.servers ? explorer.servers : [] as server (server.id)}
                    <ExplorerRow
                        href={server.href}
                        name={server.name}
                        detail={server.state.label}
                        tone={server.state.tone}
                        aside={server.note}
                        asideClass={noteClass(server.state.tone)}
                        tags={server.state.key === 'healthy' ? server.tags : []}
                        current={serverCurrent(server.href)}
                    />
                {/each}
            </div>
        {/if}
    </div>

    {#if explorer.running}
        {#key explorer.running.href}
            <Link
                href={explorer.running.href}
                class="focus-ring tint-primary flex shrink-0 flex-col gap-2 rounded-xl border p-3 no-underline"
            >
                <span class="flex items-center gap-2 text-[12px] font-semibold text-primary-strong">
                    <span class="status-dot-working size-2 rounded-full bg-primary" aria-hidden="true"></span>
                    Deploying now
                </span>
                <span class="text-[12.5px] text-foreground">
                    <span class="font-mono">{explorer.running.application}</span>
                    {#if explorer.running.server}
                        <span>to</span>
                        <span class="font-mono">{explorer.running.server}</span>
                    {/if}
                </span>
                <PhaseSegments states={explorer.running.states} />
                <span class="text-[11.5px] text-muted-foreground">
                    {sentence(explorer.running.phase)}{elapsed ? ` · ${elapsed}` : ''}{explorer.running.more > 0
                        ? ` · and ${explorer.running.more} more`
                        : ''}
                </span>
            </Link>
        {/key}
    {/if}
</aside>
