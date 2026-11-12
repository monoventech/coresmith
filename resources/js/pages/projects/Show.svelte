<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import EnvironmentCard from '@/components/projects/EnvironmentCard.svelte'
    import FileInEnvironmentDialog from '@/components/projects/FileInEnvironmentDialog.svelte'
    import ProjectNameDialog from '@/components/projects/ProjectNameDialog.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { Candidate, EnvironmentView } from '@/lib/projects'
    import { router } from '@inertiajs/svelte'

    let {
        project,
        environments = [],
        can,
        candidates
    }: {
        project: { id: string; name: string; meta: string }
        environments: EnvironmentView[]
        can: { manage: boolean }
        candidates: { applications: Candidate[]; databases: Candidate[] }
    } = $props()

    let renaming = $state(false)
    let adding = $state(false)
    let deleting = $state(false)
    let filing = $state(false)
    let renamingEnvironment = $state(false)
    let deletingEnvironment = $state(false)
    let chosen = $state<EnvironmentView | null>(null)
    let working = $state(false)

    function open(environment: EnvironmentView, then: () => void) {
        chosen = environment
        then()
    }

    function remove(href: string, done: () => void) {
        working = true
        router.delete(href, { preserveScroll: true, onFinish: () => ((working = false), done()) })
    }
</script>

<AppHead title={project.name} />

<BackLink href={route('projects.index')} label="Projects" />

<div class="mb-6 flex flex-wrap items-end justify-between gap-6 max-sm:mb-4 max-sm:gap-4">
    <div class="min-w-0">
        <h1 class="type-h1 m-0 wrap-anywhere max-sm:text-[24px]">{project.name}</h1>
        <p class="type-body-sm mt-1.5 text-muted-foreground max-sm:text-[12.5px]">{project.meta}</p>
    </div>
    {#if can.manage}
        <div class="flex flex-wrap gap-2">
            <Button variant="ghost" onclick={() => (renaming = true)}>Rename</Button>
            <Button variant="outline" onclick={() => (adding = true)}>Add environment</Button>
            <Button variant="ghost" class="text-destructive-strong" onclick={() => (deleting = true)}>Delete</Button>
        </div>
    {/if}
</div>

<div class="flex flex-col gap-4 max-sm:gap-2.5">
    {#each environments as environment (environment.id)}
        <EnvironmentCard
            {environment}
            manage={can.manage}
            onfill={() => open(environment, () => (filing = true))}
            onrename={() => open(environment, () => (renamingEnvironment = true))}
            ondelete={() => open(environment, () => (deletingEnvironment = true))}
        />
    {/each}
</div>

<FileInEnvironmentDialog bind:open={filing} environment={chosen} {candidates} />

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

    <ProjectNameDialog
        bind:open={adding}
        title="Add an environment"
        description="Add apps and databases to it once it exists. Anyone the whole project is shared with sees it too."
        label="Environment name"
        submit="Add environment"
        href={route('projects.environments.store', project.id)}
        maxlength={32}
    />

    <ProjectNameDialog
        bind:open={renamingEnvironment}
        title="Rename {chosen?.name}"
        description="Only Coresmith uses the name. Nothing on your servers changes."
        label="Environment name"
        submit="Save"
        href={chosen?.rename_href ?? ''}
        method="put"
        initial={chosen?.name ?? ''}
        maxlength={32}
    />

    <Dialog.Root bind:open={deletingEnvironment}>
        <Dialog.Content>
            <Dialog.Header>
                <Dialog.Title>Delete {chosen?.name}?</Dialog.Title>
                <Dialog.Description
                    >What's in it stays where it runs and shows under No project. Anyone it was shared with loses access
                    to it through {chosen?.name}.</Dialog.Description
                >
            </Dialog.Header>
            <Dialog.Footer>
                <Button variant="ghost" onclick={() => (deletingEnvironment = false)}>Cancel</Button>
                <Button
                    variant="destructive"
                    disabled={working}
                    onclick={() => chosen && remove(chosen.delete_href, () => (deletingEnvironment = false))}
                    >Delete {chosen?.name}</Button
                >
            </Dialog.Footer>
        </Dialog.Content>
    </Dialog.Root>

    <Dialog.Root bind:open={deleting}>
        <Dialog.Content>
            <Dialog.Header>
                <Dialog.Title>Delete {project.name}?</Dialog.Title>
                <Dialog.Description
                    >Its apps and databases stay where they run and move to No project. Anyone it was shared with loses
                    access to them through it.</Dialog.Description
                >
            </Dialog.Header>
            <Dialog.Footer>
                <Button variant="ghost" onclick={() => (deleting = false)}>Cancel</Button>
                <Button
                    variant="destructive"
                    disabled={working}
                    onclick={() => remove(route('projects.destroy', project.id), () => (deleting = false))}
                    >Delete {project.name}</Button
                >
            </Dialog.Footer>
        </Dialog.Content>
    </Dialog.Root>
{/if}
