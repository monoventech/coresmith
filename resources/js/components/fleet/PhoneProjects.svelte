<script lang="ts">
    import type { ExplorerApp, ExplorerProject, ExplorerProps } from '@/lib/panel'
    import { statusDot } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { Link } from '@inertiajs/svelte'
    import { ChevronDown, ChevronRight, Folder } from '@lucide/svelte'

    let { explorer }: { explorer: ExplorerProps } = $props()

    let open = $state(false)

    function standing(apps: ExplorerApp[]): { tone: string; word: string } | null {
        if (apps.length === 0) {
            return null
        }

        const notable =
            apps.find((app) => app.state.tone === 'accent') ?? apps.find((app) => app.state.tone === 'destructive')

        if (notable) {
            return { tone: notable.state.tone, word: notable.state.label.toLowerCase() }
        }

        return apps.every((app) => app.state.key === 'live')
            ? { tone: 'success', word: 'live' }
            : { tone: apps[0].state.tone, word: apps[0].state.label.toLowerCase() }
    }

    function summary(project: ExplorerProject): { name: string; tone: string; word: string }[] {
        return project.environments.flatMap((environment) => {
            const state = standing(environment.apps)

            return state === null ? [] : [{ name: environment.name, ...state }]
        })
    }
</script>

<section class="mb-5 sm:hidden" aria-labelledby="phone-projects-label" data-testid="phone-projects">
    <h2 id="phone-projects-label" class="mb-2 text-[11.5px] font-semibold tracking-[0.04em] text-muted-foreground">
        Projects
    </h2>
    <ul class="m-0 flex list-none flex-col overflow-hidden rounded-xl border border-border bg-card p-0">
        {#each explorer.projects as project (project.id)}
            <li class="border-t border-border first:border-t-0">
                <Link
                    href={project.href}
                    class="focus-ring flex min-h-14 items-center gap-2.5 px-3.5 py-2 text-foreground no-underline"
                >
                    <Folder class="size-4.25 shrink-0 text-muted-foreground" />
                    <span class="flex min-w-0 flex-1 flex-col leading-snug">
                        <span class="truncate text-[14.5px] font-medium">{project.name}</span>
                        <span class="flex flex-wrap items-center gap-x-2.5 text-[12px] text-muted-foreground">
                            {#each summary(project) as environment (environment.name)}
                                <span class="flex items-center gap-1.5">
                                    <span
                                        class={cn('size-1.5 shrink-0 rounded-full', statusDot(environment.tone))}
                                        aria-hidden="true"
                                    ></span>
                                    {environment.name}
                                    {environment.word}
                                </span>
                            {/each}
                        </span>
                    </span>
                    <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
                </Link>
            </li>
        {/each}
        {#if explorer.unfiled.length > 0}
            <li class="border-t border-border first:border-t-0">
                <button
                    type="button"
                    class="focus-ring flex min-h-12 w-full cursor-pointer items-center gap-2.5 bg-transparent px-3.5 py-2 text-left text-[13px] text-muted-foreground"
                    aria-expanded={open}
                    aria-controls="phone-apps"
                    onclick={() => (open = !open)}
                >
                    <span>Apps</span>
                    <span class="min-w-0 flex-1 truncate font-mono text-[12px]"
                        >{explorer.unfiled.map((app) => app.name).join(', ')}</span
                    >
                    {#if open}
                        <ChevronDown class="size-4 shrink-0" />
                    {:else}
                        <ChevronRight class="size-4 shrink-0" />
                    {/if}
                </button>
                {#if open}
                    <ul id="phone-apps" class="m-0 flex list-none flex-col border-t border-border p-0">
                        {#each explorer.unfiled as app (app.id)}
                            <li class="border-t border-border first:border-t-0">
                                <Link
                                    href={app.href}
                                    aria-label="{app.name}, {app.state.label}"
                                    class="focus-ring flex min-h-11 items-center gap-2.5 px-3.5 py-2 text-foreground no-underline"
                                >
                                    <span
                                        class={cn('size-1.75 shrink-0 rounded-full', statusDot(app.state.tone))}
                                        aria-hidden="true"
                                    ></span>
                                    <span class="min-w-0 flex-1 truncate font-mono text-[13px]">{app.name}</span>
                                    {#if app.server}
                                        <span
                                            class="shrink-0 font-mono text-[11.5px] text-muted-foreground"
                                            aria-hidden="true">{app.server}</span
                                        >
                                    {/if}
                                </Link>
                            </li>
                        {/each}
                    </ul>
                {/if}
            </li>
        {/if}
    </ul>
</section>
