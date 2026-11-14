<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link } from '@inertiajs/svelte'
    import { Search } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import ProjectScopeSelect from '@/components/projects/ProjectScopeSelect.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { cn } from '@/lib/utils'

    type Row = {
        id: string
        name: string
        server: string | null
        environment: string | null
        href: string
        release: string | null
        tag: string | null
        when: string | null
        full: string | null
    }

    type Group = { name: string | null; apps: Row[] }

    let {
        groups = [],
        scope = '',
        scopes = []
    }: {
        groups: Group[]
        scope?: string
        scopes?: { value: string; label: string }[]
    } = $props()

    let query = $state('')

    const columns =
        'grid grid-cols-[minmax(0,1.3fr)_minmax(0,0.8fr)_minmax(0,1.5fr)_minmax(0,0.8fr)] gap-3 max-[900px]:grid-cols-1 max-[900px]:gap-1.5'
    const total = $derived(groups.reduce((sum, group) => sum + group.apps.length, 0))
    const needle = $derived(query.trim().toLowerCase())
    const shown = $derived(
        needle === ''
            ? groups
            : groups
                  .map((group) => ({
                      ...group,
                      apps: group.apps.filter((app) =>
                          [app.name, app.server, app.environment, group.name].some((field) =>
                              field?.toLowerCase().includes(needle)
                          )
                      )
                  }))
                  .filter((group) => group.apps.length > 0)
    )
    const matching = $derived(shown.reduce((sum, group) => sum + group.apps.length, 0))
    const count = $derived(
        needle === ''
            ? `${total} ${total === 1 ? 'app' : 'apps'}`
            : `${matching} of ${total} ${total === 1 ? 'app' : 'apps'}`
    )
</script>

<AppHead title="Releases" />

<div class="mb-2">
    <h1 class="type-h1 m-0">Releases</h1>
    <p class="type-body-sm mt-1.5 text-muted-foreground">
        Every deploy gets its own timestamped folder. Rolling back switches to an older one, so nothing is rebuilt.
    </p>
</div>

{#if groups.length === 0}
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
            <Input type="search" bind:value={query} placeholder="Search apps" aria-label="Search apps" class="pl-9" />
        </div>
        <ProjectScopeSelect {scopes} {scope} class="block w-56 max-sm:w-full" />
        <span class="text-[12.5px] text-muted-foreground" data-testid="releases-count" aria-live="polite">{count}</span>
    </div>

    {#if shown.length === 0}
        <div class="mt-5">
            <EmptyState
                title="No app matches “{query.trim()}”."
                description="Search looks at app names, servers, environments and projects."
            >
                {#snippet action()}
                    <Button variant="outline" onclick={() => (query = '')}>Clear the search</Button>
                {/snippet}
            </EmptyState>
        </div>
    {/if}

    {#each shown as group (group.name ?? '')}
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
                            <span class="block truncate font-mono text-[13px] font-medium">{app.name}</span>
                            {#if app.environment}
                                <span
                                    class="mt-0.5 block truncate text-[11.5px] text-muted-foreground max-[900px]:hidden"
                                    >{app.environment}</span
                                >
                            {/if}
                            {#if app.environment || app.server}
                                <span
                                    class="mt-0.5 hidden truncate text-[11.5px] text-muted-foreground max-[900px]:block"
                                    >{[app.environment, app.server].filter(Boolean).join(' · ')}</span
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
{/if}
