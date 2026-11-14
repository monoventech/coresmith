<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { router } from '@inertiajs/svelte'
    import { ChevronsUpDown } from '@lucide/svelte'
    import { Button } from '@/components/ui/button'
    import * as Command from '@/components/ui/command'
    import * as Popover from '@/components/ui/popover'

    type App = {
        id: string
        name: string
        server: string | null
        project: string | null
        href: string
        current: boolean
    }

    let { applications }: { applications: App[] } = $props()

    let open = $state(false)

    const groups = $derived(
        [...new Set(applications.map((app) => app.project ?? ''))]
            .sort((a, b) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b)))
            .map((project) => ({ project, apps: applications.filter((app) => (app.project ?? '') === project) }))
    )

    function go(app: App) {
        open = false

        if (!app.current) {
            router.visit(app.href)
        }
    }
</script>

<Popover.Root bind:open>
    <Popover.Trigger>
        {#snippet child({ props })}
            <Button variant="outline" size="sm" {...props}>
                Switch app
                <ChevronsUpDown class="size-4 opacity-60" aria-hidden="true" />
            </Button>
        {/snippet}
    </Popover.Trigger>
    <Popover.Content align="start" class="w-80 p-0 max-sm:w-[calc(100vw-2rem)]">
        <Command.Root>
            <Command.Input placeholder="Search apps" aria-label="Search apps" aria-controls="app-switcher-list" />
            <Command.List
                id="app-switcher-list"
                tabindex={0}
                class="focus-ring max-h-80 rounded-md"
                data-testid="app-switcher"
            >
                <Command.Empty class="px-2 py-3 text-[12.5px] text-muted-foreground">No app matches.</Command.Empty>
                {#each groups as group (group.project)}
                    <Command.Group heading={group.project || 'No project'}>
                        {#each group.apps as app (app.id)}
                            <Command.Item
                                value={app.id}
                                keywords={[app.name, app.server ?? '', group.project]}
                                data-checked={app.current}
                                onSelect={() => go(app)}
                            >
                                <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]">{app.name}</span>
                                {#if app.server}
                                    <span class="text-[11.5px] text-muted-foreground">{app.server}</span>
                                {/if}
                            </Command.Item>
                        {/each}
                    </Command.Group>
                {/each}
            </Command.List>
        </Command.Root>
    </Popover.Content>
</Popover.Root>
