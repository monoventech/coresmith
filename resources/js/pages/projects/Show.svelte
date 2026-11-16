<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import MetricBar from '@/components/fleet/MetricBar.svelte'
    import AddToProjectDialog from '@/components/projects/AddToProjectDialog.svelte'
    import ProjectNameDialog from '@/components/projects/ProjectNameDialog.svelte'
    import TagEditor from '@/components/projects/TagEditor.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import * as Popover from '@/components/ui/popover'
    import { toneText } from '@/lib/fleet'
    import type {
        Candidate,
        Place,
        ProjectApp,
        ProjectAttention,
        ProjectDatabase,
        ProjectDeploy,
        ProjectPerson,
        ProjectServer
    } from '@/lib/projects'
    import { ENGINES } from '@/lib/projects'
    import { cn } from '@/lib/utils'
    import { Link, router } from '@inertiajs/svelte'
    import { ArrowUpRight, ChevronDown } from '@lucide/svelte'

    let {
        project,
        attention = [],
        applications = [],
        databases = [],
        deploys = [],
        deploys_href,
        servers = [],
        people = null,
        access_href = null,
        add = [],
        can,
        shared_part = false,
        adds_stay_private = false,
        candidates,
        tag_suggestions = []
    }: {
        project: { id: string; name: string; meta: string; healthy: boolean }
        attention: ProjectAttention[]
        applications: ProjectApp[]
        databases: ProjectDatabase[]
        deploys: ProjectDeploy[]
        deploys_href: string
        servers: ProjectServer[]
        people: ProjectPerson[] | null
        access_href: string | null
        add: { name: string; href: string }[]
        can: { manage: boolean; fill: boolean }
        shared_part: boolean
        adds_stay_private: boolean
        candidates: { applications: Candidate[]; databases: Candidate[] }
        tag_suggestions: string[]
    } = $props()

    let renaming = $state(false)
    let deleting = $state(false)
    let adding = $state(false)
    let picking = $state(false)
    let working = $state<string | null>(null)

    const empty = $derived(applications.length === 0 && databases.length === 0)
    const bringIn = $derived(can.fill && candidates.applications.length + candidates.databases.length > 0)
    const row = 'border-t border-border px-5.5 py-3 first:border-t-0 max-sm:px-4'
    const dot = "before:mr-1.5 before:text-muted-foreground before:content-['·']"

    function deploy(href: string) {
        working = href
        router.post(href, { from: 'project' }, { onFinish: () => (working = null) })
    }

    function takeOut(href: string) {
        working = href
        router.delete(href, { preserveScroll: true, onFinish: () => (working = null) })
    }

    function remove() {
        working = 'project'
        router.delete(route('projects.destroy', project.id), { onFinish: () => (working = null) })
    }
</script>

{#snippet where(server: Place)}
    on {#if server.href}<Link href={server.href} class="focus-ring rounded-sm font-mono text-muted-foreground"
            >{server.name}</Link
        >{:else}<span class="font-mono">{server.name}</span>{/if}
{/snippet}

{#snippet addApp()}
    {#if add.length === 1}
        <Button href={add[0].href}>Add an app</Button>
    {:else if add.length > 1}
        <Popover.Root bind:open={picking}>
            <Popover.Trigger>
                {#snippet child({ props })}
                    <Button {...props}>
                        Add an app
                        <ChevronDown class="size-4 opacity-70" aria-hidden="true" />
                    </Button>
                {/snippet}
            </Popover.Trigger>
            <Popover.Content align="end" class="w-64 p-1.5" aria-label="Pick a server for the new app">
                <p class="m-0 px-2.5 pt-1.5 pb-1 text-[12px] text-muted-foreground">Which server should run it?</p>
                <ul class="m-0 flex list-none flex-col p-0">
                    {#each add as target (target.href)}
                        <li>
                            <Link
                                href={target.href}
                                class="focus-ring flex min-h-10 items-center rounded-md px-2.5 font-mono text-[13px] text-foreground no-underline hover:bg-muted"
                                onclick={() => (picking = false)}>{target.name}</Link
                            >
                        </li>
                    {/each}
                </ul>
            </Popover.Content>
        </Popover.Root>
    {/if}
{/snippet}

<AppHead title={project.name} />

<BackLink href={route('projects.index')} label="Projects" />

<div class="mb-6 flex flex-wrap items-end justify-between gap-6 max-sm:mb-4 max-sm:gap-4">
    <div class="min-w-0">
        <h1 class="type-h1 m-0 wrap-anywhere max-sm:text-[24px]">{project.name}</h1>
        <p class="type-body-sm mt-1.5 text-muted-foreground max-sm:text-[12.5px]">{project.meta}</p>
    </div>
    {#if !empty || can.manage}
        <div class="flex flex-wrap items-center gap-2 max-sm:w-full max-sm:flex-col max-sm:items-stretch">
            {#if !empty && (add.length > 0 || bringIn)}
                <div class="flex gap-2 max-sm:grid max-sm:auto-cols-fr max-sm:grid-flow-col max-sm:*:w-full">
                    {@render addApp()}
                    {#if bringIn}
                        <Button variant="outline" onclick={() => (adding = true)}>Add existing</Button>
                    {/if}
                </div>
            {/if}
            {#if can.manage}
                <div class="flex gap-2">
                    <Button variant="ghost" onclick={() => (renaming = true)}>Rename</Button>
                    <Button variant="ghost" class="text-destructive-strong" onclick={() => (deleting = true)}
                        >Delete</Button
                    >
                </div>
            {/if}
        </div>
    {/if}
</div>

{#if attention.length > 0}
    <section
        class="mb-4 overflow-hidden rounded-xl border border-border bg-card max-sm:mb-2.5"
        aria-labelledby="attention-heading"
        data-testid="project-attention"
    >
        <h2 id="attention-heading" class="type-label m-0 px-5.5 pt-4 pb-1 text-muted-foreground max-sm:px-4">
            Needs attention
        </h2>
        <ul class="m-0 list-none p-0">
            {#each attention as item, index (`${index}:${item.subject}`)}
                <li
                    class="flex min-h-11 flex-wrap items-center gap-x-2.5 gap-y-0.5 px-5.5 py-1.5 text-[13.5px] max-sm:px-4"
                >
                    <StateDot tone={item.severity === 'critical' ? 'destructive' : 'warning'} />
                    {#if item.href}
                        <Link href={item.href} class="focus-ring rounded-sm font-mono font-medium text-foreground"
                            >{item.subject}</Link
                        >
                    {:else}
                        <span class="font-mono font-medium">{item.subject}</span>
                    {/if}
                    <span class="text-muted-foreground">{item.summary}</span>
                </li>
            {/each}
        </ul>
    </section>
{:else if project.healthy}
    <p
        class="m-0 mb-4 flex items-center gap-2 text-[13px] text-muted-foreground max-sm:mb-2.5"
        data-testid="project-healthy"
    >
        <StateDot tone="success" state="live" size="sm" />
        Everything is running.
    </p>
{/if}

{#if empty}
    <EmptyState
        title={shared_part ? `Nothing in ${project.name} is shared with you yet.` : `Nothing in ${project.name} yet.`}
        description={can.fill
            ? shared_part
                ? 'Apps you add to it show here.'
                : 'Add an app, or bring in apps and databases you already have.'
            : 'Apps and databases show here once someone adds them.'}
    >
        {#snippet action()}
            {#if add.length > 0 || bringIn}
                <div class="flex flex-wrap gap-2">
                    {@render addApp()}
                    {#if bringIn}
                        <Button variant="outline" onclick={() => (adding = true)}>Add existing</Button>
                    {/if}
                </div>
            {/if}
        {/snippet}
    </EmptyState>
{:else}
    <div class="flex flex-col gap-4 max-sm:gap-2.5">
        {#if applications.length > 0}
            <section
                class="overflow-hidden rounded-xl border border-border bg-card"
                aria-labelledby="apps-heading"
                data-testid="project-apps"
            >
                <h2 id="apps-heading" class="type-label m-0 px-5.5 pt-4 pb-1 text-muted-foreground max-sm:px-4">
                    Apps
                </h2>
                <ul class="m-0 list-none p-0">
                    {#each applications as application (application.id)}
                        {@const last = application.last_deploy}
                        <li class={cn(row, 'flex flex-wrap items-center gap-x-4 gap-y-2.5')} data-testid="project-app">
                            <div class="min-w-0 flex-1 basis-80">
                                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                    <StateDot tone={application.state.tone} state={application.state.key} />
                                    <Link
                                        href={application.href}
                                        class="focus-ring rounded-sm font-mono text-[14px] font-medium wrap-anywhere text-foreground"
                                        >{application.name}</Link
                                    >
                                    <span
                                        class={cn(
                                            'text-[12px] font-medium whitespace-nowrap',
                                            toneText(application.state.tone)
                                        )}>{application.state.label}</span
                                    >
                                    <TagEditor
                                        name={application.name}
                                        tags={application.tags}
                                        href={application.tags_href}
                                        suggestions={tag_suggestions}
                                    />
                                </div>
                                <p
                                    class="m-0 mt-1 flex flex-wrap items-center gap-x-1.5 pl-4.5 text-[12.5px] text-muted-foreground"
                                >
                                    <span>{application.kind}</span>
                                    <span class={dot}>{@render where(application.server)}</span>
                                    {#if application.domain}
                                        <span class={dot}
                                            ><a
                                                href={application.domain.href}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="focus-ring inline-flex items-center gap-0.5 rounded-sm font-mono text-muted-foreground hover:text-foreground"
                                                >{application.domain.label}<ArrowUpRight
                                                    class="size-3"
                                                    aria-hidden="true"
                                                /><span class="sr-only"> (opens in a new tab)</span></a
                                            ></span
                                        >
                                    {/if}
                                </p>
                                <p
                                    class="m-0 mt-1 flex flex-wrap items-center gap-x-1.5 pl-4.5 text-[12.5px] text-muted-foreground"
                                    data-testid="last-deploy"
                                >
                                    {#if last}
                                        <span>
                                            {#if last.href}
                                                <Link
                                                    href={last.href}
                                                    class={cn(
                                                        'focus-ring rounded-sm font-medium',
                                                        toneText(last.state.tone)
                                                    )}>{last.state.label}</Link
                                                >
                                            {:else}
                                                <span class={cn('font-medium', toneText(last.state.tone))}
                                                    >{last.state.label}</span
                                                >
                                            {/if}
                                            <span title={last.full}>{last.when}</span>
                                        </span>
                                        {#if last.commit}
                                            <span class={cn(dot, 'min-w-0 text-foreground')}>{last.commit}</span>
                                        {/if}
                                        {#if last.by}
                                            <span class={dot}>{last.by}</span>
                                        {/if}
                                    {:else}
                                        Not deployed yet
                                    {/if}
                                </p>
                            </div>
                            {#if application.deploy_href || application.remove_href}
                                <div class="flex shrink-0 flex-wrap gap-1.5 pl-4.5 max-sm:w-full">
                                    {#if application.deploy_href}
                                        {@const href = application.deploy_href}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={working !== null || application.state.key === 'deploying'}
                                            onclick={() => deploy(href)}
                                            aria-label="Deploy {application.name}">Deploy</Button
                                        >
                                    {/if}
                                    {#if application.remove_href}
                                        {@const href = application.remove_href}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={working !== null}
                                            onclick={() => takeOut(href)}
                                            aria-label="Take {application.name} out of {project.name}">Take out</Button
                                        >
                                    {/if}
                                </div>
                            {/if}
                        </li>
                    {/each}
                </ul>
            </section>
        {/if}

        {#if databases.length > 0}
            <section
                class="overflow-hidden rounded-xl border border-border bg-card"
                aria-labelledby="databases-heading"
                data-testid="project-databases"
            >
                <h2 id="databases-heading" class="type-label m-0 px-5.5 pt-4 pb-1 text-muted-foreground max-sm:px-4">
                    Databases
                </h2>
                <ul class="m-0 list-none p-0">
                    {#each databases as database (database.id)}
                        <li
                            class={cn(row, 'flex min-h-13 flex-wrap items-center gap-x-4 gap-y-2')}
                            data-testid="project-database"
                        >
                            <div class="min-w-0 flex-1 basis-64">
                                <Link
                                    href={database.href}
                                    class="focus-ring rounded-sm font-mono text-[14px] font-medium wrap-anywhere text-foreground"
                                    >{database.name}</Link
                                >
                                <p
                                    class="m-0 mt-0.5 flex flex-wrap items-center gap-x-1.5 text-[12.5px] text-muted-foreground"
                                >
                                    <span>{ENGINES[database.type] ?? database.type}</span>
                                    <span class={dot}>{@render where(database.server)}</span>
                                    {#if database.offsite}
                                        <span class={cn(dot, 'font-medium', toneText(database.offsite.tone))}
                                            >{database.offsite.label}</span
                                        >
                                    {/if}
                                </p>
                            </div>
                            {#if database.remove_href}
                                {@const href = database.remove_href}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={working !== null}
                                    onclick={() => takeOut(href)}
                                    aria-label="Take {database.name} out of {project.name}">Take out</Button
                                >
                            {/if}
                        </li>
                    {/each}
                </ul>
            </section>
        {/if}
    </div>
{/if}

{#snippet sidebar()}
    {#if servers.length > 0}
        <section
            class="rounded-xl border border-border bg-card px-5.5 pt-4 pb-3 max-sm:px-4"
            aria-labelledby="servers-heading"
            data-testid="project-servers"
        >
            <h2 id="servers-heading" class="type-label m-0 mb-1 text-muted-foreground">Runs on</h2>
            <ul class="m-0 flex list-none flex-col p-0">
                {#each servers as server (server.id)}
                    <li class="border-t border-border py-2.5 first:border-t-0">
                        <div class="flex min-h-6 items-center gap-2">
                            {#if server.state}
                                <StateDot tone={server.state.tone} state={server.state.key} size="sm" />
                            {/if}
                            {#if server.href}
                                <Link
                                    href={server.href}
                                    class="focus-ring min-w-0 truncate rounded-sm font-mono text-[13px] font-medium text-foreground"
                                    >{server.name}</Link
                                >
                            {:else}
                                <span class="min-w-0 truncate font-mono text-[13px] text-muted-foreground"
                                    >{server.name}</span
                                >
                            {/if}
                            {#if server.state}
                                <span class={cn('ml-auto text-[12px] whitespace-nowrap', toneText(server.state.tone))}
                                    >{server.state.label}</span
                                >
                            {/if}
                        </div>
                        {#if server.cpu !== null || server.memory !== null || server.disk !== null}
                            <div class="mt-2 grid grid-cols-3 gap-3">
                                <MetricBar label="CPU" value={server.cpu} warnAt={85} />
                                <MetricBar label="Memory" value={server.memory} warnAt={85} />
                                <MetricBar label="Disk" value={server.disk} warnAt={85} />
                            </div>
                        {/if}
                    </li>
                {/each}
            </ul>
        </section>
    {/if}

    {#if people !== null}
        <section
            class="rounded-xl border border-border bg-card px-5.5 pt-4 pb-3 max-sm:px-4"
            aria-labelledby="people-heading"
            data-testid="project-people"
        >
            <h2 id="people-heading" class="type-label m-0 mb-1 text-muted-foreground">Who can see it</h2>
            <ul class="m-0 flex list-none flex-col p-0">
                {#each people as person (person.name)}
                    <li
                        class="flex min-h-10 flex-wrap items-center gap-x-2 border-t border-border py-1.5 first:border-t-0"
                    >
                        <span class="min-w-0 truncate text-[13px] font-medium">{person.name}</span>
                        <span class="text-[12px] text-muted-foreground">{person.role}</span>
                        <span class="ml-auto text-[12px] text-muted-foreground">{person.reach}</span>
                    </li>
                {/each}
            </ul>
            {#if access_href}
                <Link
                    href={access_href}
                    class="focus-ring mt-1.5 inline-block rounded-sm text-[12.5px] font-medium text-primary-strong"
                    >Change who can see it</Link
                >
            {/if}
        </section>
    {/if}
{/snippet}

{#if deploys.length > 0}
    <div
        class="mt-4 grid grid-cols-[minmax(0,1fr)_20rem] items-start gap-4 max-lg:grid-cols-1 max-sm:mt-2.5 max-sm:gap-2.5"
    >
        <section
            class="overflow-hidden rounded-xl border border-border bg-card"
            aria-labelledby="deploys-heading"
            data-testid="project-deploys"
        >
            <div class="flex items-center justify-between gap-3 px-5.5 pt-4 pb-1 max-sm:px-4">
                <h2 id="deploys-heading" class="type-label m-0 text-muted-foreground">Recent deploys</h2>
                <Link
                    href={deploys_href}
                    class="focus-ring rounded-sm text-[12.5px] font-medium text-muted-foreground hover:text-foreground"
                    >See all</Link
                >
            </div>
            <ul class="m-0 list-none px-5.5 pb-2 max-sm:px-4">
                {#each deploys as deployment (deployment.id)}
                    <li class="flex min-h-10 items-center gap-3 text-[13px]">
                        {#if deployment.href}
                            <Link
                                href={deployment.href}
                                aria-label="{deployment.application} deploy {deployment.release}"
                                class="focus-ring min-w-0 truncate rounded-sm font-mono text-foreground"
                                >{deployment.application}<span class="text-muted-foreground max-sm:hidden"
                                    >{` · ${deployment.release}`}</span
                                ></Link
                            >
                        {:else}
                            <span class="min-w-0 truncate font-mono"
                                >{deployment.application}<span class="text-muted-foreground max-sm:hidden"
                                    >{` · ${deployment.release}`}</span
                                ></span
                            >
                        {/if}
                        <span class="text-[12.5px] whitespace-nowrap text-muted-foreground" title={deployment.full}
                            >{deployment.when}</span
                        >
                        <span
                            class={cn(
                                'ml-auto text-[12px] font-medium whitespace-nowrap',
                                toneText(deployment.state.tone)
                            )}>{deployment.state.label}</span
                        >
                    </li>
                {/each}
            </ul>
        </section>
        <div class="flex min-w-0 flex-col gap-4 max-sm:gap-2.5">
            {@render sidebar()}
        </div>
    </div>
{:else if servers.length > 0 || people !== null}
    <div class="mt-4 grid grid-cols-2 items-start gap-4 max-md:grid-cols-1 max-sm:mt-2.5 max-sm:gap-2.5">
        {@render sidebar()}
    </div>
{/if}

{#if can.fill}
    <AddToProjectDialog
        bind:open={adding}
        project={project.name}
        href={route('projects.contents.store', project.id)}
        {candidates}
        staysPrivate={adds_stay_private}
    />
{/if}

{#if can.manage}
    <ProjectNameDialog
        bind:open={renaming}
        title="Rename project"
        description="Only Coresmith uses the name. Nothing on your servers changes."
        label="Project name"
        submit="Save"
        href={route('projects.update', project.id)}
        method="put"
        initial={project.name}
        maxlength={64}
    />

    <Dialog.Root bind:open={deleting}>
        <Dialog.Content>
            <Dialog.Header>
                <Dialog.Title class="wrap-anywhere">Delete {project.name}?</Dialog.Title>
                <Dialog.Description
                    >Its apps and databases keep running where they are and move to No project. Anyone it was shared
                    with loses access to them through it.</Dialog.Description
                >
            </Dialog.Header>
            <Dialog.Footer>
                <Button variant="ghost" onclick={() => (deleting = false)}>Cancel</Button>
                <Button variant="destructive" disabled={working !== null} onclick={remove}>Delete {project.name}</Button
                >
            </Dialog.Footer>
        </Dialog.Content>
    </Dialog.Root>
{/if}
