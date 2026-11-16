<script lang="ts">
    import AppTags from '@/components/projects/AppTags.svelte'
    import type { ExplorerProject, ExplorerProps } from '@/lib/panel'
    import { statusDot } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { Link } from '@inertiajs/svelte'
    import { ChevronDown, ChevronRight, Folder } from '@lucide/svelte'

    let { explorer }: { explorer: ExplorerProps } = $props()

    let open = $state(false)

    function summary(project: ExplorerProject): { tone: string; text: string } | null {
        const apps = project.apps

        if (apps.length === 0) {
            const count = project.databases.length

            return count === 0 ? null : { tone: 'default', text: `${count} ${count === 1 ? 'database' : 'databases'}` }
        }

        const notable =
            apps.find((app) => app.state.tone === 'accent') ??
            apps.find((app) => app.state.tone === 'destructive') ??
            apps.find((app) => app.state.key !== 'live')

        if (notable) {
            return { tone: notable.state.tone, text: `${notable.name} ${notable.state.label.toLowerCase()}` }
        }

        return { tone: 'success', text: apps.length === 1 ? '1 app, live' : `${apps.length} apps, all live` }
    }
</script>

<section class="mb-5 sm:hidden" aria-labelledby="phone-projects-label" data-testid="phone-projects">
    <h2 id="phone-projects-label" class="mb-2 text-[11.5px] font-semibold tracking-[0.04em] text-muted-foreground">
        Projects
    </h2>
    <ul class="m-0 flex list-none flex-col overflow-hidden rounded-xl border border-border bg-card p-0">
        {#each explorer.projects as project (project.id)}
            {@const state = summary(project)}
            <li class="border-t border-border first:border-t-0">
                <Link
                    href={project.href}
                    class="focus-ring flex min-h-14 items-center gap-2.5 px-3.5 py-2 text-foreground no-underline"
                >
                    <Folder class="size-4.25 shrink-0 text-muted-foreground" />
                    <span class="flex min-w-0 flex-1 flex-col leading-snug">
                        <span class="truncate text-[14.5px] font-medium">{project.name}</span>
                        {#if state}
                            <span class="flex min-w-0 items-center gap-1.5 text-[12px] text-muted-foreground">
                                <span
                                    class={cn('size-1.5 shrink-0 rounded-full', statusDot(state.tone))}
                                    aria-hidden="true"
                                ></span>
                                <span class="min-w-0 truncate">{state.text}</span>
                            </span>
                        {/if}
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
                                    aria-label={[app.name, ...app.tags, app.state.label].join(', ')}
                                    class="focus-ring flex min-h-11 items-center gap-2.5 px-3.5 py-2 text-foreground no-underline"
                                >
                                    <span
                                        class={cn('size-1.75 shrink-0 rounded-full', statusDot(app.state.tone))}
                                        aria-hidden="true"
                                    ></span>
                                    <span class="min-w-0 flex-1 truncate font-mono text-[13px]">{app.name}</span>
                                    {#if app.tags.length > 0}
                                        <AppTags
                                            tags={app.tags}
                                            limit={2}
                                            class="shrink-0 flex-nowrap"
                                            chipClass="max-w-20"
                                        />
                                    {:else if app.server}
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
