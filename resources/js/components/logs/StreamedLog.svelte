<script lang="ts">
    import { ArrowDown, ChevronDown, ChevronUp, Search } from '@lucide/svelte'
    import { onDestroy, untrack } from 'svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { kindOf, matchesIn, pieces } from '@/lib/logs/lines'
    import type { Reveal } from '@/lib/logs/reveal.svelte'
    import { notify } from '@/lib/toast.svelte'
    import { cn } from '@/lib/utils'

    let {
        log,
        running,
        filename,
        label,
        empty,
        class: className = '',
        testid = 'deployment-log'
    }: {
        log: Reveal
        running: boolean
        filename: string
        label: string
        empty: string
        class?: string
        testid?: string
    } = $props()

    const WRAP_KEY = 'log-wrap'
    const ANNOUNCE_MS = 5000

    let terminal = $state<HTMLDivElement | null>(null)
    let finder = $state<HTMLInputElement | null>(null)
    let query = $state('')
    let chosen = $state(0)
    let wrap = $state(readWrap())
    let following = $state(true)
    let unseen = $state(false)
    let copied = $state(false)
    let flashed = $state<number | null>(null)
    let announcement = $state('')

    let errorsChecked = 0
    let errorAt = -1
    let errorsGeneration = -1
    let announced = untrack(() => log.lines.length)
    let announcedGeneration = untrack(() => log.generation)
    let announceTimer: ReturnType<typeof setTimeout> | null = null
    let copyTimer: ReturnType<typeof setTimeout> | null = null
    let flashTimer: ReturnType<typeof setTimeout> | null = null

    const needle = $derived(query.trim())

    const matches = $derived.by(() => {
        const total = log.lines.length
        const text = needle

        void log.revealing

        if (text === '') {
            return []
        }

        return untrack(() => {
            const found: Array<{ line: number; start: number }> = []

            for (let line = 0; line < total; line++) {
                for (const start of matchesIn(log.lines[line], text)) {
                    found.push({ line, start })
                }
            }

            return found
        })
    })

    const current = $derived(matches.length === 0 ? null : matches[Math.min(chosen, matches.length - 1)])
    const currentKey = $derived(current ? `${current.line}:${current.start}` : '')

    const firstError = $derived.by(() => {
        const total = log.lines.length
        const settled = !log.revealing
        const generation = log.generation

        return untrack(() => {
            if (generation !== errorsGeneration) {
                errorsGeneration = generation
                errorsChecked = 0
                errorAt = -1
            }

            const complete = settled ? total : total - 1

            for (; errorAt === -1 && errorsChecked < complete; errorsChecked++) {
                if (kindOf(log.lines[errorsChecked]) === 'error') {
                    errorAt = errorsChecked
                }
            }

            return errorAt
        })
    })

    function readWrap(): boolean {
        try {
            return localStorage.getItem(WRAP_KEY) !== 'off'
        } catch {
            return true
        }
    }

    function toggleWrap() {
        wrap = !wrap

        try {
            localStorage.setItem(WRAP_KEY, wrap ? 'on' : 'off')
        } catch {
            return
        }
    }

    function atBottom(element: HTMLDivElement): boolean {
        return element.scrollHeight - element.scrollTop - element.clientHeight < 24
    }

    function scrolled() {
        if (!terminal) {
            return
        }

        following = atBottom(terminal)

        if (following) {
            unseen = false
        }
    }

    function jumpToEnd() {
        if (!terminal) {
            return
        }

        following = true
        unseen = false
        terminal.scrollTop = terminal.scrollHeight
        terminal.focus({ preventScroll: true })
    }

    function scrollToLine(index: number) {
        const element = terminal?.querySelector<HTMLElement>(`[data-line="${index}"]`)

        if (!terminal || !element) {
            return
        }

        following = false
        terminal.scrollTop = element.offsetTop - terminal.clientHeight / 2 + element.offsetHeight / 2
    }

    function step(by: number) {
        if (matches.length === 0) {
            return
        }

        chosen = (Math.min(chosen, matches.length - 1) + by + matches.length) % matches.length
    }

    function findKeys(event: KeyboardEvent) {
        if (event.key === 'Enter') {
            event.preventDefault()
            step(event.shiftKey ? -1 : 1)
        } else if (event.key === 'Escape' && query !== '') {
            event.preventDefault()
            query = ''
        }
    }

    function shortcuts(event: KeyboardEvent) {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'f') {
            event.preventDefault()
            finder?.focus()
            finder?.select()
        }
    }

    function showFirstError() {
        if (firstError === -1) {
            return
        }

        scrollToLine(firstError)
        flashed = firstError

        if (flashTimer) {
            clearTimeout(flashTimer)
        }

        flashTimer = setTimeout(() => (flashed = null), 1600)
    }

    async function copy() {
        try {
            await navigator.clipboard.writeText(log.text)
            copied = true
            announcement = 'Copied.'

            if (copyTimer) {
                clearTimeout(copyTimer)
            }

            copyTimer = setTimeout(() => (copied = false), 2000)
        } catch {
            notify("Couldn't copy the output.", 'Download it instead, or select the text and copy it.', 'warning')
        }
    }

    function download() {
        const url = URL.createObjectURL(new Blob([log.text], { type: 'text/plain;charset=utf-8' }))
        const link = document.createElement('a')

        link.href = url
        link.download = filename
        link.hidden = true
        document.body.append(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 0)
    }

    $effect(() => {
        void needle
        chosen = 0
    })

    $effect(() => {
        if (currentKey !== '') {
            scrollToLine(untrack(() => current?.line ?? 0))
        }
    })

    $effect(() => {
        void log.lines.length
        void log.lines[log.lines.length - 1]

        if (!terminal) {
            return
        }

        if (untrack(() => following)) {
            terminal.scrollTop = terminal.scrollHeight
        } else {
            unseen = true
        }
    })

    $effect(() => {
        const total = log.lines.length

        if (log.generation !== announcedGeneration) {
            announcedGeneration = log.generation
            announced = total
        }

        if (!running || total <= announced || announceTimer !== null) {
            return
        }

        announceTimer = setTimeout(() => {
            announceTimer = null

            const fresh = log.lines.length - announced

            announced += fresh

            if (fresh > 0) {
                announcement = fresh === 1 ? '1 new line.' : `${fresh} new lines.`
            }
        }, ANNOUNCE_MS)
    })

    onDestroy(() => {
        for (const timer of [announceTimer, copyTimer, flashTimer]) {
            if (timer) {
                clearTimeout(timer)
            }
        }
    })
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<section class="flex min-w-0 flex-col gap-2.5" onkeydown={shortcuts}>
    <div class="flex flex-wrap items-center gap-2 max-sm:order-last" role="group" aria-label="Output tools">
        <div class="flex min-w-0 flex-1 basis-64 items-center gap-1.5">
            <div class="relative max-w-80 min-w-0 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    bind:ref={finder}
                    type="search"
                    bind:value={query}
                    placeholder="Find in output"
                    aria-label="Find in output"
                    aria-describedby="{testid}-matches"
                    class="h-9 pr-20 pl-9 max-sm:h-11"
                    onkeydown={findKeys}
                    data-testid="log-find"
                />
                <span
                    id="{testid}-matches"
                    class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 font-mono text-[12px] text-muted-foreground"
                    aria-live="polite"
                    data-testid="log-find-count"
                    >{needle === ''
                        ? ''
                        : matches.length === 0
                          ? 'No matches'
                          : `${Math.min(chosen, matches.length - 1) + 1} of ${matches.length}`}</span
                >
            </div>
            <Button
                variant="outline"
                size="icon-sm"
                aria-label="Previous match"
                disabled={matches.length === 0}
                onclick={() => step(-1)}
            >
                <ChevronUp aria-hidden="true" />
            </Button>
            <Button
                variant="outline"
                size="icon-sm"
                aria-label="Next match"
                disabled={matches.length === 0}
                onclick={() => step(1)}
            >
                <ChevronDown aria-hidden="true" />
            </Button>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                class="px-2.5 aria-pressed:bg-muted max-sm:hidden"
                aria-pressed={wrap}
                onclick={toggleWrap}
                data-testid="log-wrap">Wrap lines</Button
            >
            <Button
                variant="outline"
                size="sm"
                class="px-2.5"
                disabled={firstError === -1}
                onclick={showFirstError}
                data-testid="log-first-error">First error</Button
            >
            <Button variant="outline" size="sm" class="px-2.5" onclick={copy} data-testid="log-copy"
                >{copied ? 'Copied' : 'Copy'}</Button
            >
            <Button
                variant="outline"
                size="sm"
                class="px-2.5 max-sm:hidden"
                onclick={download}
                data-testid="log-download">Download</Button
            >
        </div>
    </div>

    <div class="relative min-w-0">
        <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
        <div
            bind:this={terminal}
            class={cn('terminal relative', className)}
            data-testid={testid}
            data-following={following}
            tabindex="0"
            role="log"
            aria-live="off"
            aria-label={label}
            onscroll={scrolled}
        >
            {#each log.lines as line, index (index)}
                {@const kind = kindOf(line)}
                <div
                    class={cn(
                        `log-${kind}`,
                        wrap ? 'wrap-anywhere whitespace-pre-wrap' : 'w-max min-w-full whitespace-pre',
                        index >= log.settled && 'log-line-new',
                        flashed === index && 'log-line-flash'
                    )}
                    data-line={index}
                    data-kind={kind}
                >
                    {#each pieces(line, needle, current?.line === index ? current.start : null) as piece, part (part)}<span
                            class={cn(
                                piece.part === 'time' && 'log-time',
                                piece.part === 'phase' && 'log-phase',
                                piece.mark === 'match' && 'log-match',
                                piece.mark === 'current' && 'log-match-current'
                            )}
                            data-mark={piece.mark}>{piece.text}</span
                        >{/each}{#if running && index === log.lines.length - 1}<span
                            class="log-cursor"
                            aria-hidden="true">▍</span
                        >{/if}
                </div>
            {:else}
                <div class="opacity-60">{empty}</div>
                {#if running}
                    <div class="log-cursor" aria-hidden="true">▍</div>
                {/if}
            {/each}
        </div>
        {#if unseen && !following}
            <button
                type="button"
                class="log-pill focus-ring absolute bottom-3.5 left-1/2 inline-flex h-9 -translate-x-1/2 items-center gap-1.5 rounded-md border px-3.5 font-sans text-[12.5px] font-medium shadow-md max-sm:h-11"
                onclick={jumpToEnd}
                data-testid="log-new-output"
            >
                <ArrowDown class="size-3.5" aria-hidden="true" />New output
            </button>
        {/if}
    </div>
    <p class="sr-only" aria-live="polite" data-testid="log-announcement">{announcement}</p>
</section>
