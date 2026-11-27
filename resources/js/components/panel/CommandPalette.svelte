<script lang="ts" module>
    import type { PaletteItem } from '@/lib/palette'

    const cache: Record<string, PaletteItem[]> = {}
</script>

<script lang="ts">
    import type { PanelProps } from '@/lib/panel'
    import type { Destination, PaletteKind } from '@/lib/palette'
    import {
        DEFAULT_LIMIT,
        destinations,
        HEADINGS,
        hasDestinations,
        MACHINE,
        readRecent,
        remember,
        score,
        search
    } from '@/lib/palette'
    import { trackedPointer } from '@/lib/pointer'
    import { themeState } from '@/lib/theme.svelte'
    import { cn } from '@/lib/utils'
    import { page, router } from '@inertiajs/svelte'
    import {
        Activity,
        ArrowLeft,
        Bell,
        ChevronRight,
        ChevronsDown,
        Cloud,
        CreditCard,
        Database,
        Folder,
        GitBranch,
        History,
        KeyRound,
        Layers,
        LayoutGrid,
        Network,
        Plus,
        Rocket,
        ScrollText,
        Search,
        Server,
        Settings,
        Shield,
        SquareTerminal,
        SunMoon,
        TriangleAlert,
        Undo2,
        User,
        Users
    } from '@lucide/svelte'
    import { tick } from 'svelte'
    import { fade } from 'svelte/transition'

    type Row =
        { id: string; type: 'item'; item: PaletteItem } | { id: string; type: 'more'; kind: PaletteKind; label: string }
    type Section = { key: string; heading: string; rows: Row[]; note?: string }

    let { open = $bindable(false) }: { open?: boolean } = $props()

    const theme = themeState()

    const icons = {
        activity: Activity,
        bell: Bell,
        cloud: Cloud,
        'credit-card': CreditCard,
        database: Database,
        folder: Folder,
        'git-branch': GitBranch,
        history: History,
        'key-round': KeyRound,
        layers: Layers,
        'layout-grid': LayoutGrid,
        network: Network,
        plus: Plus,
        rocket: Rocket,
        'scroll-text': ScrollText,
        server: Server,
        settings: Settings,
        shield: Shield,
        'sun-moon': SunMoon,
        terminal: SquareTerminal,
        'triangle-alert': TriangleAlert,
        'undo-2': Undo2,
        user: User,
        users: Users
    } as const

    const KEY =
        'mr-1 inline-block min-w-4.5 rounded-sm border border-border px-1 text-center font-mono text-[10.5px] leading-4'

    const CONTEXT: Record<string, string> = {
        app: 'This app',
        server: 'This server',
        database: 'This database'
    }

    let query = $state('')
    let highlighted = $state(0)
    let loading = $state(false)
    let expanded = $state<PaletteKind | null>(null)
    let focus = $state<PaletteItem | null>(null)
    let announcement = $state('')
    let input = $state<HTMLInputElement | null>(null)
    let returnFocus: HTMLElement | null = null
    let before = { query: '', highlighted: 0, expanded: null as PaletteKind | null }

    const pointer = trackedPointer()

    const team = $derived((page.props.panel as PanelProps | null)?.team?.id ?? '')
    const subject = $derived((page.props.subject as string | null | undefined) ?? null)
    const fresh = $derived(page.props.palette as PaletteItem[] | undefined)
    const items = $derived(fresh ?? cache[team] ?? [])
    const byId = $derived(new Map(items.map((item) => [item.id, item])))

    $effect(() => {
        if (fresh) {
            cache[team] = fresh
        }
    })

    $effect(() => {
        if (team && subject) {
            remember(team, subject)
        }
    })

    function here(href: string | undefined): boolean {
        if (!href) {
            return false
        }

        try {
            return new URL(href, window.location.origin).pathname === window.location.pathname
        } catch {
            return false
        }
    }

    function itemRows(list: PaletteItem[]): Row[] {
        return list.map((item) => ({ id: item.id, type: 'item' as const, item }))
    }

    const sections = $derived.by((): Section[] => {
        const needle = query.trim()

        if (focus) {
            const all: Destination[] = destinations(focus)
            const shown = needle === '' ? all : all.filter((destination) => score(destination, needle) > 0)

            return [{ key: 'focus', heading: focus.label, rows: itemRows(shown) }]
        }

        if (needle !== '') {
            return search(items, needle, expanded).map((group) => ({
                key: group.key,
                heading: group.heading,
                rows: [
                    ...itemRows(group.items),
                    ...(group.more > 0 && group.key !== expanded
                        ? [
                              {
                                  id: `more:${group.key}`,
                                  type: 'more' as const,
                                  kind: group.key as PaletteKind,
                                  label: `Show all ${group.total} ${HEADINGS[group.key as PaletteKind].noun}`
                              }
                          ]
                        : [])
                ],
                note:
                    group.more > 0 && group.key === expanded
                        ? `Showing ${group.items.length} of ${group.total}. Type more to narrow it down.`
                        : undefined
            }))
        }

        const current = subject ? byId.get(subject) : undefined
        const context = current ? destinations(current).filter((destination) => !here(destination.href)) : []
        const recent = readRecent(team)
            .filter((id) => id !== subject)
            .map((id) => byId.get(id))
            .filter((item): item is PaletteItem => item !== undefined)
            .slice(0, DEFAULT_LIMIT)
        const actions = items.filter((item) => item.kind === 'action').slice(0, DEFAULT_LIMIT)
        const pages =
            context.length === 0 && recent.length === 0
                ? items.filter((item) => item.kind === 'page' && !here(item.href)).slice(0, DEFAULT_LIMIT)
                : []

        return [
            ...(current && context.length > 0
                ? [{ key: 'context', heading: CONTEXT[current.kind] ?? 'This page', rows: itemRows(context) }]
                : []),
            ...(recent.length > 0 ? [{ key: 'recent', heading: 'Recent', rows: itemRows(recent) }] : []),
            ...(actions.length > 0 ? [{ key: 'actions', heading: 'Actions', rows: itemRows(actions) }] : []),
            ...(pages.length > 0 ? [{ key: 'pages', heading: 'Go to', rows: itemRows(pages) }] : [])
        ]
    })

    const rows = $derived(sections.flatMap((section) => section.rows))
    const indexOf = $derived(new Map(rows.map((row, index) => [row.id, index])))

    $effect(() => {
        if (!open) {
            return
        }

        returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
        query = ''
        highlighted = 0
        expanded = null
        focus = null
        announcement = ''

        void tick().then(() => input?.focus())

        loading = true
        router.reload({ only: ['palette'], onFinish: () => (loading = false) })

        return () => returnFocus?.focus()
    })

    function close() {
        open = false
    }

    function run(row: Row) {
        if (row.type === 'more') {
            const first = rows.findIndex((each) => each.type === 'item' && each.item.kind === row.kind)

            expanded = row.kind
            highlighted = Math.max(0, first) + 5

            return
        }

        const item = row.item

        close()

        if (item.action === 'theme') {
            theme.updateAppearance(theme.resolvedAppearance() === 'dark' ? 'light' : 'dark')

            return
        }

        if (item.href) {
            router.visit(item.href)
        }
    }

    function enter(item: PaletteItem) {
        before = { query, highlighted, expanded }
        focus = item
        query = ''
        highlighted = 0
        announcement = `${item.label}'s pages`
        input?.focus()
    }

    function back() {
        focus = null
        query = before.query
        expanded = before.expanded
        highlighted = before.highlighted
        announcement = 'Back to the results'
        input?.focus()
    }

    function hover(event: PointerEvent, index: number) {
        if (pointer.moved(event)) {
            highlighted = index
        }
    }

    function atEnd(): boolean {
        return (
            input === null || (input.selectionStart === input.value.length && input.selectionEnd === input.value.length)
        )
    }

    function onKeydown(event: KeyboardEvent) {
        const count = rows.length
        const row = rows[highlighted]

        if (event.key === 'ArrowDown') {
            event.preventDefault()
            highlighted = count ? (highlighted + 1) % count : 0
        } else if (event.key === 'ArrowUp') {
            event.preventDefault()
            highlighted = count ? (highlighted - 1 + count) % count : 0
        } else if (event.key === 'Enter') {
            if (row) {
                event.preventDefault()
                run(row)
            }
        } else if (event.key === 'ArrowRight') {
            if (!focus && row?.type === 'item' && hasDestinations(row.item) && atEnd()) {
                event.preventDefault()
                enter(row.item)
            }
        } else if (event.key === 'ArrowLeft' || event.key === 'Backspace') {
            if (focus && query === '') {
                event.preventDefault()
                back()
            }
        } else if (event.key === 'Escape') {
            event.preventDefault()

            if (focus) {
                back()
            } else {
                close()
            }
        } else if (event.key === 'Tab') {
            event.preventDefault()
        }
    }

    function typed() {
        highlighted = 0

        if (!focus) {
            expanded = null
        }
    }
</script>

{#if open}
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div
        class="panel fixed inset-0 z-55 flex items-start justify-center bg-scrim px-6 pt-24 pb-6 max-sm:px-4 max-sm:pt-16"
        onclick={close}
        transition:fade={{ duration: 200 }}
    >
        <div
            class="w-full max-w-130 overflow-hidden rounded-xl border border-border bg-popover text-popover-foreground shadow-(--shadow-lg)"
            role="dialog"
            aria-modal="true"
            aria-label="Command palette"
            tabindex="-1"
            onclick={(event) => event.stopPropagation()}
        >
            <div class="flex items-center gap-2.5 border-b border-border px-4.5 py-3.5">
                {#if focus}
                    <button
                        type="button"
                        tabindex="-1"
                        class="-my-1 -ml-1 inline-flex size-6 cursor-pointer items-center justify-center rounded-md border-0 bg-transparent text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label="Back to the results"
                        onclick={back}
                    >
                        <ArrowLeft class="size-4" aria-hidden="true" />
                    </button>
                {:else}
                    <Search class="size-4 text-muted-foreground" aria-hidden="true" />
                {/if}
                <input
                    bind:this={input}
                    bind:value={query}
                    oninput={typed}
                    onkeydown={onKeydown}
                    placeholder={focus ? `Search ${focus.label}'s pages…` : 'Search or jump to…'}
                    class="min-w-0 flex-1 border-none bg-transparent font-sans text-[14px] text-foreground outline-none placeholder:text-muted-foreground"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="palette-results"
                    aria-activedescendant={rows[highlighted] ? `palette-option-${highlighted}` : undefined}
                    aria-describedby="palette-keys"
                    aria-label="Search commands"
                    autocomplete="off"
                    spellcheck="false"
                />
                <span class="rounded-sm border border-border px-1.25 py-px font-mono text-[11px] text-muted-foreground"
                    >esc</span
                >
            </div>

            {#if rows.length > 0}
                <div id="palette-results" class="max-h-80 overflow-y-auto p-1.5" role="listbox" aria-label="Commands">
                    {#each sections as section (section.key)}
                        <div role="group" aria-labelledby="palette-group-{section.key}" data-testid="palette-group">
                            <div
                                id="palette-group-{section.key}"
                                class={cn(
                                    'px-3 pt-2 pb-1 text-[11.5px] font-medium text-muted-foreground',
                                    section.key === 'focus' && focus && MACHINE.includes(focus.kind) && 'font-mono'
                                )}
                            >
                                {section.heading}
                            </div>
                            {#each section.rows as row (row.id)}
                                {@const index = indexOf.get(row.id) ?? 0}
                                {@const Icon =
                                    row.type === 'more'
                                        ? ChevronsDown
                                        : (icons[row.item.icon as keyof typeof icons] ?? Layers)}
                                <button
                                    id="palette-option-{index}"
                                    type="button"
                                    role="option"
                                    tabindex="-1"
                                    aria-selected={index === highlighted}
                                    class={cn(
                                        'group flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-md border-0 bg-transparent px-3 text-left font-sans text-[13.5px] text-foreground transition-colors duration-(--dur-fast) hover:bg-muted',
                                        index === highlighted && 'bg-muted'
                                    )}
                                    onpointerenter={pointer.enter}
                                    onpointermove={(event) => hover(event, index)}
                                    onclick={() => run(row)}
                                    data-testid={row.type === 'more' ? 'palette-more' : undefined}
                                >
                                    <Icon class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                    {#if row.type === 'more'}
                                        <span class="flex-1 text-muted-foreground">{row.label}</span>
                                    {:else}
                                        <span
                                            class="flex min-w-0 flex-1 items-center gap-3 max-sm:flex-col max-sm:items-start max-sm:gap-0 max-sm:py-1.5"
                                        >
                                            <span
                                                class={cn(
                                                    'min-w-0 flex-1 truncate font-medium max-sm:w-full max-sm:flex-none',
                                                    !focus && MACHINE.includes(row.item.kind) && 'font-mono text-[13px]'
                                                )}>{row.item.label}</span
                                            >
                                            <span
                                                class="max-w-[45%] shrink-0 truncate text-[12px] text-muted-foreground max-sm:max-w-full"
                                                >{row.item.hint}</span
                                            >
                                        </span>
                                        {#if !focus && hasDestinations(row.item)}
                                            <span
                                                class={cn(
                                                    '-mr-1.5 inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity duration-(--dur-fast) group-hover:opacity-100 hover:bg-background hover:text-foreground pointer-coarse:opacity-100',
                                                    index === highlighted && 'opacity-100'
                                                )}
                                                aria-hidden="true"
                                                data-testid="palette-inside"
                                                onclick={(event) => {
                                                    event.stopPropagation()
                                                    enter(row.item)
                                                }}
                                            >
                                                <ChevronRight class="size-4" />
                                            </span>
                                        {/if}
                                    {/if}
                                </button>
                            {/each}
                            {#if section.note}
                                <p class="m-0 px-3 py-2 text-[12px] text-muted-foreground">{section.note}</p>
                            {/if}
                        </div>
                    {/each}
                </div>
            {:else}
                <div id="palette-results" class="p-1.5" role="status">
                    <p class="m-0 px-3 py-3 text-[13px] text-muted-foreground">
                        {loading && items.length === 0
                            ? 'Gathering commands…'
                            : focus
                              ? `Nothing in ${focus.label} matches “${query}”.`
                              : `Nothing matches “${query}”.`}
                    </p>
                </div>
            {/if}

            <p
                id="palette-keys"
                class="m-0 flex flex-wrap items-center gap-x-3.5 gap-y-1 border-t border-border px-4.5 py-2 text-[11.5px] text-muted-foreground pointer-coarse:hidden"
            >
                <span><kbd class={KEY}>↑</kbd><kbd class={KEY}>↓</kbd> to move</span>
                <span><kbd class={KEY}>↵</kbd> to open</span>
                <span><kbd class={KEY}>→</kbd> for its pages</span>
                <span><kbd class={KEY}>esc</kbd> to close</span>
            </p>
            <p class="sr-only" aria-live="polite">{announcement}</p>
        </div>
    </div>
{/if}
