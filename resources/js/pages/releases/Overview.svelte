<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, page, router } from '@inertiajs/svelte'
    import { Search } from '@lucide/svelte'
    import { untrack } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Pager from '@/components/Pager.svelte'
    import AppTags from '@/components/projects/AppTags.svelte'
    import ProjectScopeSelect from '@/components/projects/ProjectScopeSelect.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import type { Pages } from '@/lib/paging'
    import { cn } from '@/lib/utils'

    type Row = {
        id: string
        name: string
        server: string | null
        tags: string[]
        href: string
        release: string | null
        tag: string | null
        when: string | null
        full: string | null
    }

    type Group = { name: string | null; apps: Row[] }

    let {
        groups = [],
        pages = null,
        search = '',
        total = 0,
        scope = '',
        scopes = []
    }: {
        groups: Group[]
        pages?: Pages | null
        search?: string
        total?: number
        scope?: string
        scopes?: { value: string; label: string }[]
    } = $props()

    let query = $state(untrack(() => search))
    let timer: ReturnType<typeof setTimeout> | undefined

    const columns =
        'grid grid-cols-[minmax(0,1.3fr)_minmax(0,0.8fr)_minmax(0,1.5fr)_minmax(0,0.8fr)] gap-3 max-[900px]:grid-cols-1 max-[900px]:gap-1.5'
    const shown = $derived(groups.reduce((sum, group) => sum + group.apps.length, 0))
    const noun = $derived(total === 1 ? 'app' : 'apps')
    const count = $derived(search === '' ? `${total} ${noun}` : `${pages?.total ?? shown} of ${total} ${noun}`)

    $effect(() => {
        const stop = router.on('before', (event) => {
            if (event.detail.visit.url.pathname !== window.location.pathname) {
                clearTimeout(timer)
            }
        })

        return () => {
            stop()
            clearTimeout(timer)
        }
    })

    function find(value: string) {
        clearTimeout(timer)
        timer = setTimeout(() => go(value), 250)
    }

    function go(value: string) {
        clearTimeout(timer)
        const q = value.trim()

        router.get(
            page.url.split('?')[0],
            { ...(scope ? { project: scope } : {}), ...(q ? { q } : {}) },
            { preserveState: true, preserveScroll: true, replace: true, only: ['groups', 'pages', 'search', 'total'] }
        )
    }

    function clear() {
        query = ''
        go('')
    }
</script>

<AppHead title="Releases" />

<div class="mb-2">
    <h1 class="type-h1 m-0">Releases</h1>
    <p class="type-body-sm mt-1.5 text-muted-foreground">
        Every deploy gets its own timestamped folder. Rolling back switches to an older one, so nothing is rebuilt.
    </p>
</div>

{#if total === 0}
    <ProjectScopeSelect {scopes} {scope} class="mt-4 block w-56 max-sm:w-full" />
    <div class="mt-5">
        <EmptyState
            title="No apps yet."
            description="Once you add an app, it shows here with the release it's running. Open it to see every release and roll back."
        >
            {#snippet action()}
                <Button variant="outline" href={route('dashboard')}>Open the fleet</Button>
            {/snippet}
        </EmptyState>
    </div>
{:else}
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <div class="relative w-72 max-sm:w-full">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                type="search"
                bind:value={query}
                oninput={(event: Event) => find((event.currentTarget as HTMLInputElement).value)}
                placeholder="Search apps"
                aria-label="Search apps"
                class="pl-9"
            />
        </div>
        <ProjectScopeSelect {scopes} {scope} keep={search ? { q: search } : {}} class="block w-56 max-sm:w-full" />
        <span class="text-[12.5px] text-muted-foreground" data-testid="releases-count" aria-live="polite">{count}</span>
    </div>

    {#if groups.length === 0}
        <div class="mt-5">
            <EmptyState
                title="No app matches “{search}”."
                description="Search looks at app names, tags, servers and projects."
            >
                {#snippet action()}
                    <Button variant="outline" onclick={clear}>Clear the search</Button>
                {/snippet}
            </EmptyState>
        </div>
    {/if}

    {#each groups as group, index (`${index}:${group.name ?? ''}`)}
        <section class="mt-6" aria-label={group.name ?? 'No project'} data-testid="releases-group">
            <h2 class="mb-2.5 text-[11.5px] font-semibold tracking-[0.04em] text-muted-foreground">
                {group.name ?? 'No project'}
            </h2>
            <div class="overflow-hidden rounded-xl border border-border bg-card">
                {#each group.apps as app (app.id)}
                    <Link
                        href={app.href}
                        class={cn(
                            columns,
                            'focus-ring min-h-13 items-center border-b border-border px-5.5 py-2 text-[13.5px] text-foreground no-underline transition-colors duration-(--dur-fast) last:border-b-0 hover:bg-muted'
                        )}
                        data-testid="releases-app"
                    >
                        <span class="block min-w-0">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="min-w-0 truncate font-mono text-[13px] font-medium">{app.name}</span>
                                <AppTags tags={app.tags} limit={2} class="shrink-0 flex-nowrap" chipClass="max-w-24" />
                            </span>
                            {#if app.server}
                                <span
                                    class="mt-0.5 hidden truncate text-[11.5px] text-muted-foreground max-[900px]:block"
                                    >{app.server}</span
                                >
                            {/if}
                        </span>
                        <span class="truncate text-muted-foreground max-[900px]:hidden">{app.server ?? ''}</span>
                        <span class="block min-w-0">
                            {#if app.release}
                                <span class="block truncate font-mono text-[12.5px]">{app.release}</span>
                                {#if app.tag}
                                    <span
                                        class="mt-0.5 block font-mono text-[11.5px] text-muted-foreground max-[900px]:hidden"
                                        >{app.tag}</span
                                    >
                                {/if}
                            {:else}
                                <span class="text-[12.5px] text-muted-foreground">Nothing live yet</span>
                            {/if}
                            {#if app.tag || app.when}
                                <span class="mt-0.5 hidden text-[11.5px] text-muted-foreground max-[900px]:block"
                                    >{[app.tag, app.when].filter(Boolean).join(' · ')}</span
                                >
                            {/if}
                        </span>
                        <span class="text-muted-foreground max-[900px]:hidden" title={app.full ?? undefined}
                            >{app.when ?? ''}</span
                        >
                    </Link>
                {/each}
            </div>
        </section>
    {/each}

    {#if pages}
        <Pager {pages} label="App pages" noun="apps" class="mt-6" />
    {/if}
{/if}
