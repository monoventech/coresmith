<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import type { Candidate } from '@/lib/projects'
    import { router } from '@inertiajs/svelte'
    import { untrack } from 'svelte'

    let {
        open = $bindable(false),
        project,
        href,
        candidates,
        staysPrivate = false
    }: {
        open: boolean
        project: string
        href: string
        candidates: { applications: Candidate[]; databases: Candidate[] }
        staysPrivate?: boolean
    } = $props()

    let applications = $state<string[]>([])
    let databases = $state<string[]>([])
    let error = $state<string | undefined>()
    let saving = $state(false)

    const groups = $derived([
        { key: 'applications' as const, label: 'Apps', items: candidates.applications },
        { key: 'databases' as const, label: 'Databases', items: candidates.databases }
    ])
    const nothing = $derived(groups.every((group) => group.items.length === 0))
    const moving = $derived(groups.some((group) => group.items.some((item) => item.where !== 'No project')))
    const chosen = $derived(applications.length + databases.length)

    $effect(() => {
        if (open) {
            untrack(() => {
                applications = []
                databases = []
                error = undefined
            })
        }
    })

    function tick(list: string[], id: string, on: boolean): string[] {
        return on ? [...list.filter((each) => each !== id), id] : list.filter((each) => each !== id)
    }

    function toggle(key: 'applications' | 'databases', id: string, on: boolean) {
        if (key === 'applications') {
            applications = tick(applications, id, on)
        } else {
            databases = tick(databases, id, on)
        }
    }

    function save(event: SubmitEvent) {
        event.preventDefault()
        saving = true
        router.post(
            href,
            { applications, databases },
            {
                preserveScroll: true,
                onSuccess: () => (open = false),
                onError: (errors: Record<string, string>) => (error = errors.applications ?? errors.databases),
                onFinish: () => (saving = false)
            }
        )
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content>
        <form onsubmit={save} novalidate data-testid="add-to-project-dialog">
            <Dialog.Header>
                <Dialog.Title class="wrap-anywhere">Add to {project}</Dialog.Title>
                <Dialog.Description>
                    {staysPrivate
                        ? 'The same people can see them as before. Nothing moves between servers.'
                        : `Anyone ${project} is shared with will see what you add. Nothing moves between servers.`}
                </Dialog.Description>
            </Dialog.Header>
            {#if nothing}
                <p class="m-0 mt-4 text-[13px] text-muted-foreground">
                    Every app and database you can add is already here.
                </p>
            {:else}
                <div class="mt-4 max-h-[45vh] overflow-y-auto pr-1">
                    {#each groups as group (group.key)}
                        {#if group.items.length > 0}
                            <fieldset class="m-0 mb-2 border-0 p-0">
                                <legend class="type-label mb-1 text-muted-foreground">{group.label}</legend>
                                {#each group.items as item (item.id)}
                                    <div class="flex min-h-10 items-center gap-2.5" data-testid="add-option">
                                        <Checkbox
                                            id="add-{item.id}"
                                            checked={(group.key === 'applications' ? applications : databases).includes(
                                                item.id
                                            )}
                                            onCheckedChange={(on) => toggle(group.key, item.id, on)}
                                        />
                                        <Label
                                            for="add-{item.id}"
                                            class="min-w-0 flex-1 truncate font-mono text-[13px] font-normal"
                                            >{item.name}</Label
                                        >
                                        <span class="max-w-[45%] shrink-0 truncate text-[12px] text-muted-foreground"
                                            >{item.where === 'No project' ? item.where : `In ${item.where}`}</span
                                        >
                                    </div>
                                {/each}
                            </fieldset>
                        {/if}
                    {/each}
                </div>
                {#if moving}
                    <p class="m-0 mt-2 text-[12.5px] text-muted-foreground">
                        Each app or database belongs to one project, so one from another project moves here.
                    </p>
                {/if}
                <FieldError message={error} />
            {/if}
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                {#if !nothing}
                    <Button type="submit" disabled={saving || chosen === 0}>Add to {project}</Button>
                {/if}
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
