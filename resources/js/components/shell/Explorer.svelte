<script lang="ts">
    import ExplorerRow from '@/components/shell/ExplorerRow.svelte'
    import PhaseSegments from '@/components/shell/PhaseSegments.svelte'
    import { elapsedSince, explorerState, hideExplorer, toggleProject } from '@/lib/explorer.svelte'
    import { getInitials } from '@/lib/initials'
    import type { ExplorerProps, ExplorerProject } from '@/lib/panel'
    import { isMac, pathOf } from '@/lib/panel'
    import { ENGINES } from '@/lib/projects'
    import { Link, page } from '@inertiajs/svelte'
    import { ChevronDown, ChevronRight, ChevronsUpDown, Folder, PanelLeftClose, Plus, Search } from '@lucide/svelte'

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
        const count = project.environments.reduce((total, environment) => total + environment.apps.length, 0)

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
</script>

<aside
    aria-label="Explorer"
    class="panel flex h-full w-72 shrink-0 flex-col gap-3 overflow-y-auto border-r border-border bg-card px-3 py-3.5"
    data-testid="explorer"
>
    <div class="flex items-center gap-1.5">
        <button
            type="button"
            class="focus-ring flex h-10 min-w-0 flex-1 cursor-pointer items-center gap-2.5 rounded-lg bg-transparent px-2 text-left transition-colors duration-(--dur-fast) hover:bg-muted"
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
            class="focus-ring inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted"
            aria-label="Hide the explorer"
            title="Hide the explorer"
            onclick={hideExplorer}
        >
            <PanelLeftClose class="size-4" />
        </button>
    </div>

    <button
        type="button"
        class="focus-ring flex h-9 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-border bg-background px-2.5 text-left text-[13px] text-muted-foreground"
        onclick={onSearch}
    >
        <Search class="size-3.75 shrink-0" />
        <span class="flex-1 truncate">Jump to an app or server</span>
        <kbd class="rounded-sm border border-border px-1.25 py-px font-mono text-[11px]">{shortcut}</kbd>
    </button>

    {#if empty}
        <div class="flex flex-col gap-2 px-2 py-4 text-[13px] text-muted-foreground">
            <p class="m-0">No servers yet.</p>
            {#if explorer.can.createServer}
                <Link href={route('servers.create')} class="focus-ring w-fit rounded-sm font-medium text-primary-strong"
                    >Provision a server</Link
                >
            {/if}
        </div>
    {/if}

    {#if explorer.projects.length > 0}
        <div class="flex flex-col gap-0.5" role="group" aria-labelledby="explorer-projects-label">
            <div class="flex h-7 items-center justify-between px-2">
                <span
                    id="explorer-projects-label"
                    class="text-[11.5px] font-semibold tracking-[0.04em] text-muted-foreground">Projects</span
                >
                {#if explorer.can.createProject}
                    <Link
                        href={route('projects.index')}
                        class="focus-ring inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
                        aria-label="Add a project"
                        title="Add a project"><Plus class="size-3.5" /></Link
                    >
                {/if}
            </div>
            {#each explorer.projects as project (project.id)}
                {@const open = !shell.folded.includes(project.id)}
                <div class="flex flex-col gap-px" data-testid="explorer-project">
                    <div class="flex h-8 items-center gap-1.5 pr-2">
                        <button
                            type="button"
                            class="focus-ring inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground hover:bg-muted"
                            aria-label="{project.name} environments"
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
                            class="focus-ring min-w-0 flex-1 truncate rounded-sm text-[13.5px] font-medium text-foreground no-underline"
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
                            {#each project.environments as environment (environment.id)}
                                <div class="flex h-5.5 items-center pl-9.5 text-[11.5px] text-muted-foreground">
                                    {environment.name}
                                </div>
                                {#each environment.apps as app (app.id)}
                                    <ExplorerRow
                                        href={app.href}
                                        name={app.name}
                                        detail={app.state.label}
                                        tone={app.state.tone}
                                        aside={app.server}
                                        asideClass="font-mono"
                                        indent
                                        current={current(app.href)}
                                    />
                                {/each}
                                {#each environment.databases as database (database.id)}
                                    <ExplorerRow
                                        href={database.href}
                                        name={database.name}
                                        detail={ENGINES[database.engine] ?? database.engine}
                                        aside={ENGINES[database.engine] ?? database.engine}
                                        database
                                        indent
                                        current={current(database.href)}
                                    />
                                {/each}
                            {/each}
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
            <span
                id="explorer-apps-label"
                class="flex h-7 items-center px-2 text-[11.5px] font-semibold tracking-[0.04em] text-muted-foreground"
                >Apps</span
            >
            {#each explorer.unfiled as app (app.id)}
                <ExplorerRow
                    href={app.href}
                    name={app.name}
                    detail={app.state.label}
                    tone={app.state.tone}
                    aside={app.server}
                    asideClass="font-mono"
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
            <div class="flex h-7 items-center justify-between px-2">
                <span
                    id="explorer-servers-label"
                    class="text-[11.5px] font-semibold tracking-[0.04em] text-muted-foreground">Servers</span
                >
                {#if explorer.can.createServer}
                    <Link
                        href={route('servers.create')}
                        class="focus-ring inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
                        aria-label="Add a server"
                        title="Add a server"><Plus class="size-3.5" /></Link
                    >
                {/if}
            </div>
            {#each explorer.servers as server (server.id)}
                <ExplorerRow
                    href={server.href}
                    name={server.name}
                    detail={server.state.label}
                    tone={server.state.tone}
                    aside={server.note}
                    asideClass={noteClass(server.state.tone)}
                    current={serverCurrent(server.href)}
                />
            {/each}
        </div>
    {/if}

    {#if explorer.running}
        <Link
            href={explorer.running.href}
            class="focus-ring tint-primary mt-auto flex shrink-0 flex-col gap-2 rounded-xl border p-3 no-underline"
        >
            <span class="flex items-center gap-2 text-[12px] font-semibold text-primary-strong">
                <span class="status-dot-working size-2 rounded-full bg-primary" aria-hidden="true"></span>
                Deploying now
            </span>
            <span class="text-[12.5px] text-foreground">
                <span class="font-mono">{explorer.running.application}</span>{#if explorer.running.server}
                    to <span class="font-mono">{explorer.running.server}</span>{/if}
            </span>
            <PhaseSegments states={explorer.running.states} />
            <span class="text-[11.5px] text-muted-foreground">
                {sentence(explorer.running.phase)}{elapsed ? ` · ${elapsed}` : ''}{explorer.running.more > 0
                    ? ` · and ${explorer.running.more} more`
                    : ''}
            </span>
        </Link>
    {/if}
</aside>
