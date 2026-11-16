<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import AppTags from '@/components/projects/AppTags.svelte'
    import * as Command from '@/components/ui/command'
    import * as Popover from '@/components/ui/popover'
    import type { CrumbSwitch, ExplorerApp, ExplorerProps } from '@/lib/panel'
    import { statusDot } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { page, router } from '@inertiajs/svelte'
    import { ChevronsUpDown } from '@lucide/svelte'

    type Option = { id: string; name: string; href: string; tone?: string; tags?: string[]; hint?: string | null }
    type Group = { heading: string | null; options: Option[] }

    const NOUNS = {
        server: ['server', 'servers'],
        application: ['app', 'apps'],
        project: ['project', 'projects']
    } as const

    let { switcher }: { switcher: CrumbSwitch } = $props()

    const uid = $props.id()

    let open = $state(false)

    const explorer = $derived(page.props.explorer as ExplorerProps | null)
    const noun = $derived(NOUNS[switcher.kind])
    const mono = $derived(switcher.kind !== 'project')
    const groups = $derived.by((): Group[] => {
        if (!explorer) {
            return []
        }

        if (switcher.kind === 'server') {
            return [
                {
                    heading: null,
                    options: explorer.servers.map((server) => ({
                        id: server.id,
                        name: server.name,
                        href: server.href,
                        tone: server.state.tone
                    }))
                }
            ]
        }

        if (switcher.kind === 'project') {
            return [
                {
                    heading: null,
                    options: explorer.projects.map((project) => ({
                        id: project.id,
                        name: project.name,
                        href: project.href
                    }))
                }
            ]
        }

        const app = (each: ExplorerApp): Option => ({
            id: each.id,
            name: each.name,
            href: each.href,
            tone: each.state.tone,
            tags: each.tags,
            hint: each.server
        })

        return [
            ...explorer.projects
                .filter((project) => project.apps.length > 0)
                .map((project) => ({ heading: project.name, options: project.apps.map(app) })),
            ...(explorer.unfiled.length > 0 ? [{ heading: 'No project', options: explorer.unfiled.map(app) }] : [])
        ]
    })

    function go(option: Option) {
        open = false

        if (option.id !== switcher.id) {
            router.visit(option.href)
        }
    }
</script>

{#if groups.some((group) => group.options.length > 1)}
    <Popover.Root bind:open>
        <Popover.Trigger
            class="focus-ring -ml-1 inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted hover:text-foreground"
            aria-label="Switch to another {noun[0]}"
            title="Switch to another {noun[0]}"
            data-testid="crumb-switcher"
        >
            <ChevronsUpDown class="size-3.5" aria-hidden="true" />
        </Popover.Trigger>
        <Popover.Content align="start" class="w-80 p-0" data-testid="crumb-switcher-panel">
            <Command.Root>
                <Command.Input
                    placeholder="Search {noun[1]}"
                    aria-label="Search {noun[1]}"
                    aria-controls="{uid}-list"
                />
                <Command.List id="{uid}-list" tabindex={0} class="focus-ring max-h-80 rounded-md">
                    <Command.Empty class="px-2 py-3 text-[12.5px] text-muted-foreground"
                        >No {noun[0]} matches.</Command.Empty
                    >
                    {#each groups as group, index (`${index}:${group.heading ?? ''}`)}
                        <Command.Group heading={group.heading ?? undefined}>
                            {#each group.options as option (option.id)}
                                <Command.Item
                                    value={option.id}
                                    keywords={[
                                        option.name,
                                        ...(option.tags ?? []),
                                        option.hint ?? '',
                                        group.heading ?? ''
                                    ]}
                                    data-checked={option.id === switcher.id}
                                    onSelect={() => go(option)}
                                >
                                    {#if option.tone}
                                        <span
                                            class={cn('size-1.75 shrink-0 rounded-full', statusDot(option.tone))}
                                            aria-hidden="true"
                                        ></span>
                                    {/if}
                                    <span class={cn('min-w-0 flex-1 truncate text-[12.5px]', mono && 'font-mono')}
                                        >{option.name}</span
                                    >
                                    {#if option.tags && option.tags.length > 0}
                                        <AppTags
                                            tags={option.tags}
                                            limit={2}
                                            class="shrink-0 flex-nowrap"
                                            chipClass="max-w-20"
                                        />
                                    {/if}
                                    {#if option.hint}
                                        <span class="shrink-0 font-mono text-[11.5px] text-muted-foreground"
                                            >{option.hint}</span
                                        >
                                    {/if}
                                </Command.Item>
                            {/each}
                        </Command.Group>
                    {/each}
                </Command.List>
            </Command.Root>
        </Popover.Content>
    </Popover.Root>
{/if}
