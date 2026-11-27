<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { router } from '@inertiajs/svelte'
    import { ChevronsUpDown } from '@lucide/svelte'
    import * as Command from '@/components/ui/command'
    import * as Popover from '@/components/ui/popover'

    type App = { id: string; name: string; server: string | null; project: string | null; href: string }

    let { href, current }: { href: string; current: string } = $props()

    const uid = $props.id()
    const LABEL = "Show another app's releases"

    let open = $state(false)
    let query = $state('')
    let apps = $state<App[]>([])
    let total = $state(0)
    let status = $state<'loading' | 'ready' | 'failed'>('loading')
    let timer: ReturnType<typeof setTimeout> | undefined
    let asked = 0

    const groups = $derived(
        apps.reduce<{ project: string; apps: App[] }[]>((all, app) => {
            const project = app.project ?? 'No project'
            const last = all.at(-1)

            if (last?.project === project) {
                last.apps.push(app)
            } else {
                all.push({ project, apps: [app] })
            }

            return all
        }, [])
    )

    async function load(value: string) {
        const ticket = ++asked
        const url = new URL(href, window.location.origin)

        status = 'loading'

        if (value.trim() !== '') {
            url.searchParams.set('q', value.trim())
        }

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })

            if (!response.ok) {
                throw new Error(String(response.status))
            }

            const body = (await response.json()) as { apps: App[]; total: number }

            if (ticket === asked) {
                apps = body.apps
                total = body.total
                status = 'ready'
            }
        } catch {
            if (ticket === asked) {
                status = 'failed'
            }
        }
    }

    $effect(() => {
        if (!open) {
            return
        }

        query = ''
        void load('')

        return () => clearTimeout(timer)
    })

    function typed() {
        clearTimeout(timer)
        timer = setTimeout(() => void load(query), 200)
    }

    function go(app: App) {
        open = false

        if (app.id !== current) {
            router.visit(app.href)
        }
    }
</script>

<Popover.Root bind:open>
    <Popover.Trigger
        class="focus-ring inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md bg-transparent align-middle text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted hover:text-foreground pointer-coarse:size-11"
        aria-label={LABEL}
        title={LABEL}
        data-testid="app-switcher-button"
    >
        <ChevronsUpDown class="size-4.5" aria-hidden="true" />
    </Popover.Trigger>
    <Popover.Content align="start" class="w-80 p-0 max-sm:w-[calc(100vw-2rem)]">
        <Command.Root shouldFilter={false}>
            <Command.Input
                bind:value={query}
                oninput={typed}
                placeholder="Search apps"
                aria-label="Search apps"
                aria-controls="{uid}-list"
            />
            <Command.List
                id="{uid}-list"
                tabindex={0}
                class="focus-ring max-h-80 rounded-md"
                data-testid="app-switcher"
            >
                {#if status === 'failed'}
                    <p class="m-0 px-2 py-3 text-[12.5px] text-muted-foreground" role="status">
                        Couldn't load your apps.
                    </p>
                    <Command.Item value="retry" onSelect={() => void load(query)}>Try again</Command.Item>
                {:else if apps.length === 0}
                    <p class="m-0 px-2 py-3 text-[12.5px] text-muted-foreground" role="status">
                        {status === 'loading' ? 'Looking…' : 'No app matches.'}
                    </p>
                {/if}
                {#each status === 'failed' ? [] : groups as group, index (`${index}:${group.project}`)}
                    <Command.Group heading={group.project}>
                        {#each group.apps as app (app.id)}
                            <Command.Item value={app.id} data-checked={app.id === current} onSelect={() => go(app)}>
                                <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]">{app.name}</span>
                                {#if app.server}
                                    <span class="text-[11.5px] text-muted-foreground">{app.server}</span>
                                {/if}
                            </Command.Item>
                        {/each}
                    </Command.Group>
                {/each}
            </Command.List>
            {#if status !== 'failed' && total > apps.length}
                <p
                    class="m-0 border-t border-border px-3 py-2 text-[12px] text-muted-foreground"
                    data-testid="app-switcher-more"
                >
                    Showing {apps.length} of {total}. Type to narrow it down.
                </p>
            {/if}
        </Command.Root>
    </Popover.Content>
</Popover.Root>
