<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { REPOSITORY_NOT_VISIBLE } from '@/lib/github'
    import type { GitHubPick, GitHubRepository, GitHubSource } from '@/lib/github'
    import { trackedPointer } from '@/lib/pointer'
    import { cn } from '@/lib/utils'
    import { Check, CircleAlert, Search } from '@lucide/svelte'
    import { onMount, tick } from 'svelte'

    let {
        connection,
        error,
        onpick
    }: { connection: GitHubSource; error?: string; onpick?: (pick: GitHubPick) => void } = $props()

    const HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }

    const LIST_FAILED = 'Coresmith could not load the repository list. Try again in a minute.'

    const BRANCHES_FAILED = 'Coresmith could not load the branch list. Type the branch name instead.'

    let repositories = $state.raw<GitHubRepository[]>([])
    let total = $state(0)
    let truncated = $state(false)
    let loading = $state(true)
    let failure = $state<string | null>(null)
    let query = $state('')
    let highlighted = $state(-1)
    let picked = $state.raw<GitHubRepository | null>(null)
    let branchFailure = $state<string | null>(null)
    let search = $state<HTMLInputElement | null>(null)

    const pointer = trackedPointer()
    const controller = new AbortController()

    let touched = false

    const needle = $derived(query.trim().toLowerCase())
    const filtered = $derived(repositories.filter((repository) => repository.full_name.toLowerCase().includes(needle)))
    const active = $derived(filtered[highlighted] ?? null)
    const empty = $derived(!loading && failure === null && repositories.length === 0)
    const status = $derived.by(() => {
        if (loading) {
            return 'Loading repositories from GitHub…'
        }

        if (failure !== null || filtered.length > 0) {
            return ''
        }

        return empty
            ? `The GitHub App can see no repositories on ${connection.account} yet. Choose some under Manage repositories on GitHub.`
            : `No repository matches "${query.trim()}".`
    })

    function optionId(repository: GitHubRepository): string {
        return `repository-option-${repository.id}`
    }

    async function load() {
        loading = true
        failure = null
        highlighted = -1

        try {
            const response = await fetch(connection.repositories_href, { headers: HEADERS, signal: controller.signal })
            const body = (await response.json()) as {
                repositories?: GitHubRepository[]
                total?: number
                truncated?: boolean
                message?: string
            }

            if (!response.ok || !Array.isArray(body.repositories)) {
                failure = response.status === 502 && body.message ? body.message : LIST_FAILED

                return
            }

            repositories = body.repositories
            total = body.total ?? body.repositories.length
            truncated = body.truncated === true
        } catch {
            failure = LIST_FAILED
        } finally {
            loading = false
        }
    }

    async function retry(fromKeyboard: boolean) {
        await load()

        if (fromKeyboard) {
            search?.focus()
        }
    }

    async function branchesOf(repository: GitHubRepository): Promise<{ branches: string[]; failure: string | null }> {
        const fallback = { branches: [repository.default_branch], failure: BRANCHES_FAILED }

        try {
            const response = await fetch(route('source-providers.branches', [connection.id, repository.id]), {
                headers: HEADERS,
                signal: controller.signal
            })
            const body = (await response.json()) as { branches?: string[]; message?: string }

            if (response.ok && Array.isArray(body.branches)) {
                return { branches: body.branches, failure: null }
            }

            return body.message === REPOSITORY_NOT_VISIBLE ? { ...fallback, failure: REPOSITORY_NOT_VISIBLE } : fallback
        } catch {
            return fallback
        }
    }

    async function pick(repository: GitHubRepository) {
        picked = repository
        highlighted = filtered.findIndex((candidate) => candidate.id === repository.id)
        branchFailure = null
        onpick?.({
            full_name: repository.full_name,
            default_branch: repository.default_branch,
            branches: [repository.default_branch]
        })

        const result = await branchesOf(repository)

        if (controller.signal.aborted || picked?.id !== repository.id) {
            return
        }

        branchFailure = result.failure
        onpick?.({
            full_name: repository.full_name,
            default_branch: repository.default_branch,
            branches: result.branches
        })
    }

    function hover(event: PointerEvent, index: number) {
        if (pointer.moved(event)) {
            highlighted = index
        }
    }

    function press(event: PointerEvent) {
        touched = event.pointerType === 'touch'
    }

    function choose(repository: GitHubRepository) {
        if (touched) {
            touched = false
            search?.blur()
        }

        void pick(repository)
    }

    async function reveal() {
        await tick()

        if (active) {
            document.getElementById(optionId(active))?.scrollIntoView({ block: 'nearest' })
        }
    }

    function onKeydown(event: KeyboardEvent) {
        if (event.isComposing) {
            return
        }

        const count = filtered.length
        const current = highlighted < count ? highlighted : -1

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault()

            if (count === 0) {
                return
            }

            highlighted = event.key === 'ArrowDown' ? (current + 1) % count : current <= 0 ? count - 1 : current - 1
            void reveal()
        } else if (event.key === 'Enter') {
            event.preventDefault()

            if (active) {
                void pick(active)
            }
        }
    }

    onMount(() => {
        void load()

        return () => controller.abort()
    })
</script>

<div>
    <Label for="repository-search" class="mb-1.5">Repository</Label>
    <span id="repository-search-description" class="sr-only">{`Search repositories on ${connection.account}`}</span>
    <div class="relative">
        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
            id="repository-search"
            bind:ref={search}
            bind:value={query}
            role="combobox"
            aria-describedby="repository-search-description"
            aria-controls="repository-options"
            aria-expanded={filtered.length > 0}
            aria-autocomplete="list"
            aria-activedescendant={active ? optionId(active) : undefined}
            aria-invalid={error ? true : undefined}
            placeholder="Search repositories"
            autocomplete="off"
            spellcheck="false"
            class="pl-9"
            oninput={(event) => (highlighted = event.currentTarget.value.trim() === '' ? -1 : 0)}
            onkeydown={onKeydown}
        />
    </div>
    <div class="mt-2 overflow-hidden rounded-lg border border-border">
        {#if filtered.length > 0}
            <div
                id="repository-options"
                role="listbox"
                aria-label={`Repositories on ${connection.account}`}
                class="max-h-72 overflow-y-auto p-1.5"
            >
                {#each filtered as repository, index (repository.id)}
                    {@const selected = picked?.id === repository.id}
                    <button
                        id={optionId(repository)}
                        type="button"
                        role="option"
                        tabindex="-1"
                        aria-selected={selected}
                        data-highlighted={index === highlighted ? '' : undefined}
                        class={cn(
                            'flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-md border px-3 py-1.5 text-left text-foreground transition-colors duration-(--dur-fast) ease-(--ease-out-soft) data-highlighted:bg-muted',
                            selected ? 'option-selected' : 'border-transparent'
                        )}
                        onpointerenter={pointer.enter}
                        onpointermove={(event) => hover(event, index)}
                        onpointerdown={press}
                        onmousedown={(event) => event.preventDefault()}
                        onclick={() => choose(repository)}
                    >
                        <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2.5 gap-y-1">
                            <span class="max-w-full truncate font-mono text-[13px] font-medium"
                                >{repository.full_name}</span
                            >
                            {#if repository.private}
                                <Badge variant="outline">private</Badge>
                            {/if}
                        </span>
                        <span class="shrink-0 font-mono text-[12px] text-muted-foreground"
                            >{repository.default_branch}</span
                        >
                        <Check class={cn('size-4 shrink-0 text-primary', !selected && 'invisible')} />
                    </button>
                {/each}
            </div>
        {:else if failure !== null}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3.5 py-3" role="alert">
                <p class="m-0 flex min-w-0 flex-1 items-start gap-2 text-[13px] text-destructive-strong">
                    <CircleAlert class="mt-0.5 size-3.75 shrink-0" />
                    {failure}
                </p>
                <Button variant="outline" size="sm" onclick={(event: MouseEvent) => void retry(event.detail === 0)}
                    >Try again</Button
                >
            </div>
        {/if}
        <div class={cn('flex flex-wrap items-center gap-x-3 gap-y-2', status && 'px-3.5 py-3')}>
            <p class="m-0 min-w-0 flex-1 text-[13px] text-muted-foreground" role="status">{status}</p>
            {#if empty && connection.manage_url}
                <Button
                    variant="outline"
                    size="sm"
                    href={connection.manage_url}
                    target="_blank"
                    rel="noopener noreferrer">Manage repositories</Button
                >
            {/if}
        </div>
    </div>
    {#if truncated && failure === null}
        <p class="mt-1.5 text-[12px] text-muted-foreground">
            {`Showing the first ${repositories.length.toLocaleString('en')} of ${total.toLocaleString('en')} repositories. Use a Git URL for one that is not listed.`}
        </p>
    {/if}
    {#if branchFailure}
        <p class="mt-1.5 text-[12px] text-muted-foreground">{branchFailure}</p>
    {/if}
    <input type="hidden" name="source_provider_id" value={connection.id} />
    <input type="hidden" name="repository_id" value={picked?.id ?? ''} />
    <FieldError message={error} />
</div>
