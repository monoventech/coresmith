<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import FleetHeader from '@/components/fleet/FleetHeader.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import AppTags from '@/components/projects/AppTags.svelte'
    import ProjectNameDialog from '@/components/projects/ProjectNameDialog.svelte'
    import { Button } from '@/components/ui/button'
    import type { ProjectCard } from '@/lib/projects'

    let {
        counts,
        projects = [],
        unfiled = [],
        narrowed = false,
        can
    }: {
        counts: { servers: number; applications: number }
        projects: ProjectCard[]
        unfiled: { id: string; name: string; server: string | null; href: string }[]
        narrowed: boolean
        can: { create: boolean }
    } = $props()

    let creating = $state(false)
</script>

<AppHead title="Projects" />

<div data-screen="fleet">
    <FleetHeader {counts}>
        {#snippet action()}
            {#if can.create && projects.length > 0}
                <Button onclick={() => (creating = true)} class="max-sm:w-full">Create a project</Button>
            {/if}
        {/snippet}
    </FleetHeader>

    {#if projects.length === 0 && unfiled.length === 0 && narrowed}
        <EmptyState
            title="Nothing is shared with you yet."
            description="Ask an owner or admin of your team to share a project, a server or an app with you."
        />
    {:else if projects.length === 0}
        <EmptyState
            class="mb-7 max-sm:mb-4"
            title="No projects yet."
            description="Group apps and databases by what they're for, such as a product or a client, then share the whole group at once."
        >
            {#snippet action()}
                {#if can.create}
                    <Button onclick={() => (creating = true)}>Create a project</Button>
                {:else}
                    <p class="text-[13px] text-muted-foreground">Ask an owner or admin of your team to create one.</p>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <div class="mb-7 grid grid-cols-2 gap-4 max-[900px]:grid-cols-1 max-sm:mb-4 max-sm:gap-2.5">
            {#each projects as project (project.id)}
                <article
                    class="hover-card group relative rounded-xl border border-border bg-card px-5.5 py-5 text-foreground has-[.card-link:focus-visible]:shadow-[0_0_0_2px_var(--background),0_0_0_4px_var(--ring)] max-sm:p-4"
                    data-testid="project-card"
                >
                    <Link
                        href={project.href}
                        class="card-link block min-w-0 text-[15px] font-medium wrap-anywhere text-foreground no-underline outline-none after:absolute after:inset-0 after:rounded-xl after:content-[''] max-sm:text-[14px]"
                    >
                        {project.name}
                    </Link>
                    <p class="m-0 mt-1 text-[12.5px] text-muted-foreground">{project.meta}</p>
                    {#if project.apps.length > 0}
                        <ul class="m-0 mt-3 flex list-none flex-col gap-1 p-0" aria-label="Apps in {project.name}">
                            {#each project.apps.slice(0, 4) as app (app.id)}
                                <li
                                    class="flex min-w-0 items-center gap-2 text-[12.5px]"
                                    data-testid="project-card-app"
                                >
                                    <StateDot tone={app.state.tone} size="sm" />
                                    <span class="min-w-0 truncate font-mono text-foreground">{app.name}</span>
                                    <AppTags tags={app.tags} limit={2} class="flex-nowrap" />
                                </li>
                            {/each}
                            {#if project.apps.length > 4}
                                <li class="pl-3.5 text-[12px] text-muted-foreground">
                                    and {project.apps.length - 4} more
                                </li>
                            {/if}
                        </ul>
                    {/if}
                    <p class="m-0 mt-4 border-t border-border pt-3 text-[12.5px] text-muted-foreground">
                        {#if project.latest}
                            Last deploy <span class="font-mono text-foreground">{project.latest.application}</span>, {project
                                .latest.when}
                        {:else}
                            No deploys yet
                        {/if}
                    </p>
                </article>
            {/each}
        </div>
    {/if}

    {#if unfiled.length > 0}
        <section
            class="rounded-xl border border-border bg-card px-5.5 py-5 max-sm:p-4"
            aria-labelledby="no-project"
            data-testid="no-project"
        >
            <h2 id="no-project" class="type-label m-0 text-muted-foreground">No project</h2>
            <p class="m-0 mt-1 mb-2 text-[12.5px] text-muted-foreground">
                Apps show here until someone adds them to a project.
            </p>
            <ul class="m-0 flex list-none flex-col p-0">
                {#each unfiled as app (app.id)}
                    <li class="flex min-h-11 items-center gap-3 border-t border-border text-[13.5px] first:border-t-0">
                        <Link href={app.href} class="focus-ring rounded-sm font-mono font-medium text-foreground">
                            {app.name}
                        </Link>
                        {#if app.server}
                            <span class="text-[12.5px] text-muted-foreground">on {app.server}</span>
                        {/if}
                    </li>
                {/each}
            </ul>
        </section>
    {/if}
</div>

{#if can.create}
    <ProjectNameDialog
        bind:open={creating}
        title="Create a project"
        description="Add apps and databases to it once it exists."
        label="Project name"
        submit="Create project"
        href={route('projects.store')}
        maxlength={64}
    />
{/if}
