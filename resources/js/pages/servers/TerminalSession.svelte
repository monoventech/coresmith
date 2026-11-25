<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { ClipboardPaste, Copy, Maximize2, Minimize2, Search, TriangleAlert, X } from '@lucide/svelte'
    import { onDestroy, onMount, untrack } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import Shell from '@/components/terminal/Shell.svelte'
    import type { ShellApi } from '@/components/terminal/Shell.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import type { StateTone } from '@/lib/fleet'
    import type { ServerHeader } from '@/lib/servers'
    import type { Ended, Links } from '@/lib/terminal/link.svelte'
    import { TerminalLink } from '@/lib/terminal/link.svelte'
    import { cn } from '@/lib/utils'

    type Terminal = {
        id: string
        mode: 'root' | 'app'
        application: string | null
        as: string
        status: 'starting' | 'open' | 'closed'
        started_at: number | null
        ends_at: number | null
        idle_seconds: number
        ended: Ended | null
    }

    let { server, terminal, links }: { server: ServerHeader; terminal: Terminal; links: Links } = $props()

    const KEYS = [
        { label: 'Esc', send: '\x1b' },
        { label: 'Tab', send: '\t' },
        { label: 'Ctrl', send: 'ctrl' },
        { label: '↑', send: '\x1b[A', name: 'Up' },
        { label: '↓', send: '\x1b[B', name: 'Down' },
        { label: '←', send: '\x1b[D', name: 'Left' },
        { label: '→', send: '\x1b[C', name: 'Right' },
        { label: '|', send: '|' },
        { label: '~', send: '~' },
        { label: '/', send: '/' },
        { label: '-', send: '-' }
    ]

    let shell: ShellApi | null = null
    let frame = $state<HTMLElement | null>(null)
    let findInput = $state<HTMLInputElement | null>(null)
    let now = $state(Date.now())
    let finding = $state(false)
    let query = $state('')
    let missing = $state(false)
    let pasting = $state<string | null>(null)
    let ending = $state(false)
    let full = $state(false)
    let ctrl = $state(false)
    let copied = $state(false)
    let clock: ReturnType<typeof setInterval> | null = null

    const link = new TerminalLink(
        untrack(() => terminal.id),
        untrack(() => links),
        {
            write: (bytes) => shell?.write(bytes),
            reset: () => shell?.reset()
        }
    )

    const ended = $derived(link.ended ?? terminal.ended)
    const who = $derived(terminal.mode === 'root' ? 'root' : `${terminal.application}'s user`)
    const startedAt = $derived.by(() => {
        const at = link.startedAt ?? terminal.started_at

        return at === null ? null : at * 1000
    })
    const elapsed = $derived(startedAt === null ? null : Math.max(0, Math.floor((now - startedAt) / 1000)))
    const status = $derived.by((): { tone: StateTone; text: string } => {
        if (link.state === 'reconnecting') {
            return { tone: 'warning', text: 'Reconnecting' }
        }

        if (link.state === 'offline') {
            return { tone: 'destructive', text: 'No live connection' }
        }

        if (link.state === 'connecting' || elapsed === null) {
            return { tone: 'default', text: 'Opening' }
        }

        return { tone: 'success', text: `Connected · ${clockText(elapsed)}` }
    })
    const changes = $derived((ended?.changes ?? []).filter((change) => change.key !== 'unchecked'))
    const unchecked = $derived(ended?.changes.find((change) => change.key === 'unchecked') ?? null)
    const remaining = $derived(link.warning ? Math.max(0, Math.round(link.warning.endsAt - now / 1000)) : 0)
    const pasteLines = $derived(pasting === null ? [] : pasting.replace(/\r?\n$/, '').split(/\r?\n/))

    function clockText(seconds: number): string {
        const hours = Math.floor(seconds / 3600)
        const minutes = Math.floor((seconds % 3600) / 60)
        const rest = String(seconds % 60).padStart(2, '0')

        return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}` : `${minutes}:${rest}`
    }

    function inText(seconds: number): string {
        return seconds >= 60
            ? `${Math.round(seconds / 60)} ${Math.round(seconds / 60) === 1 ? 'minute' : 'minutes'}`
            : `${seconds} ${seconds === 1 ? 'second' : 'seconds'}`
    }

    function ranFor(minutes: number): string {
        return minutes < 1
            ? 'It ran for less than a minute.'
            : `It ran for ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}.`
    }

    function ready(api: ShellApi) {
        shell = api
        link.start()
        api.focus()
    }

    function paste(text: string) {
        if (/\r?\n./.test(text.replace(/\r?\n$/, ''))) {
            pasting = text
        } else {
            shell?.paste(text)
        }
    }

    async function pasteFromClipboard() {
        try {
            paste(await navigator.clipboard.readText())
        } catch {
            shell?.focus()
        }
    }

    function confirmPaste() {
        const text = pasting

        pasting = null

        if (text !== null) {
            shell?.paste(text)
        }
    }

    async function copy() {
        copied = (await shell?.copy()) ?? false
        setTimeout(() => (copied = false), 1500)
        shell?.focus()
    }

    function openFind() {
        finding = true
        queueMicrotask(() => findInput?.focus())
    }

    function closeFind() {
        finding = false
        query = ''
        missing = false
        shell?.clearFind()
        shell?.focus()
    }

    function find(forward = true) {
        missing = query !== '' && !(shell?.find(query, forward) ?? false)
    }

    function findKey(event: KeyboardEvent) {
        if (event.key === 'Enter') {
            event.preventDefault()
            find(!event.shiftKey)
        }

        if (event.key === 'Escape') {
            event.preventDefault()
            closeFind()
        }
    }

    async function toggleFull() {
        if (document.fullscreenElement) {
            await document.exitFullscreen().catch(() => undefined)
        } else {
            await frame?.requestFullscreen().catch(() => undefined)
        }

        shell?.focus()
    }

    function key(send: string) {
        if (send === 'ctrl') {
            ctrl = !ctrl
        } else {
            ctrl = false
        }

        shell?.type(send)
    }

    async function end() {
        ending = false
        await link.end()
    }

    const onFull = () => (full = document.fullscreenElement === frame && frame !== null)

    $effect(() => {
        if (!ended?.checking) {
            return
        }

        const started = Date.now()
        const poll = setInterval(() => {
            if (Date.now() - started > 60_000) {
                clearInterval(poll)

                return
            }

            void link.sync()
        }, 2000)

        return () => clearInterval(poll)
    })

    onMount(() => {
        clock = setInterval(() => (now = Date.now()), 1000)
        document.addEventListener('fullscreenchange', onFull)
    })

    onDestroy(() => {
        if (clock) {
            clearInterval(clock)
        }

        document.removeEventListener('fullscreenchange', onFull)
        link.stop()
    })
</script>

<AppHead title="Terminal · {server.name}" />

<BackLink href={server.tabs.apps} label={server.name} />

{#if ended}
    <section class="mx-auto max-w-176" aria-labelledby="terminal-ended-title" data-testid="terminal-summary">
        <p class="m-0 font-mono text-[12.5px] text-muted-foreground">{server.name} · {terminal.as}</p>
        <h1 id="terminal-ended-title" class="type-h1 m-0 mt-1.5 max-sm:text-[24px]">Session ended</h1>
        <p class="m-0 mt-2 text-[14px]">
            {ended.sentence ?? 'It ended.'}
            {ended.opened ? ranFor(ended.minutes) : ''}
        </p>

        {#if ended.opened && ended.checking}
            <p
                class="m-0 mt-5 flex items-center gap-2 text-[13.5px] text-muted-foreground"
                role="status"
                data-testid="terminal-checking"
            >
                <StateDot tone="accent" size="sm" state="running" />Checking what changed on {server.name}…
            </p>
        {:else if ended.opened && unchecked}
            <p class="m-0 mt-5 text-[13.5px] text-muted-foreground" data-testid="terminal-unchecked">
                {unchecked.detail}
            </p>
        {:else if ended.opened && changes.length === 0}
            <div class="tint-success mt-5 rounded-xl border px-4.5 py-3.5" data-testid="terminal-unchanged">
                <p class="m-0 text-[13.5px] font-medium">Everything Coresmith looks after is as it was</p>
                <p class="m-0 mt-0.5 text-[12.5px] text-muted-foreground">
                    The web server's settings, the apps, the firewall, and Coresmith's key and account.
                </p>
            </div>
        {:else if ended.opened}
            <section
                class="mt-5 rounded-xl border border-border bg-card px-5 py-4.5 max-sm:px-4"
                aria-labelledby="terminal-changed-title"
                data-testid="terminal-changes"
            >
                <h2 id="terminal-changed-title" class="m-0 text-[14px] font-medium">What changed</h2>
                <ul class="m-0 mt-3 flex list-none flex-col gap-3 p-0">
                    {#each changes as change (change.key)}
                        <li class="flex items-start gap-2.5">
                            <StateDot
                                tone={change.key === 'key' || change.key === 'running' ? 'destructive' : 'warning'}
                                size="sm"
                                class="mt-2"
                            />
                            <span class="min-w-0">
                                <span class="block text-[13.5px] font-medium">{change.title}</span>
                                <span class="block text-[13px] text-muted-foreground">{change.detail}</span>
                            </span>
                        </li>
                    {/each}
                </ul>
            </section>
        {/if}

        <div class="mt-5 flex flex-wrap gap-2.5">
            <Button href={links.again} class="max-sm:w-full">Open a new session</Button>
            {#if changes.some((change) => change.key === 'firewall' || change.key === 'key')}
                <Button href={server.tabs.hardening} variant="outline" class="max-sm:w-full">Go to Hardening</Button>
            {/if}
            <Button href={server.tabs.activity} variant="outline" class="max-sm:w-full">See it in Activity</Button>
        </div>
    </section>
{:else}
    <section
        bind:this={frame}
        class={cn('flex flex-col gap-2.5', full && 'h-dvh bg-background p-3')}
        aria-label="Terminal on {server.name}"
        data-testid="terminal-session"
    >
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5">
                <h1 class="m-0 truncate font-mono text-[15px] font-medium">{server.name}</h1>
                <Badge variant={terminal.mode === 'root' ? 'warning' : 'outline'}
                    >{terminal.mode === 'root' ? 'Root' : who}</Badge
                >
                <span
                    role="status"
                    class="inline-flex items-center gap-1.5 text-[12.5px] text-muted-foreground tabular-nums"
                    data-testid="terminal-status"
                >
                    <StateDot
                        tone={status.tone}
                        size="sm"
                        state={status.tone === 'success' ? 'live' : ''}
                    />{status.text}
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <Button variant="ghost" size="sm" onclick={openFind} aria-expanded={finding}>
                    <Search class="size-4" aria-hidden="true" /><span class="max-sm:sr-only">Find</span>
                </Button>
                <Button variant="ghost" size="sm" onclick={copy}>
                    <Copy class="size-4" aria-hidden="true" /><span class="max-sm:sr-only"
                        >{copied ? 'Copied' : 'Copy'}</span
                    >
                </Button>
                <Button variant="ghost" size="sm" onclick={pasteFromClipboard}>
                    <ClipboardPaste class="size-4" aria-hidden="true" /><span class="max-sm:sr-only">Paste</span>
                </Button>
                <Button variant="ghost" size="sm" onclick={toggleFull} class="max-sm:hidden">
                    {#if full}
                        <Minimize2 class="size-4" aria-hidden="true" />Exit full screen
                    {:else}
                        <Maximize2 class="size-4" aria-hidden="true" />Full screen
                    {/if}
                </Button>
                <Button variant="outline" size="sm" onclick={() => (ending = true)} data-testid="terminal-end">
                    End session
                </Button>
            </div>
        </div>

        {#if link.warning}
            <div
                class="tint-warning flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3"
                role="alert"
                data-testid="terminal-warning"
            >
                <TriangleAlert class="size-4.5 shrink-0 text-warning" aria-hidden="true" />
                <div class="min-w-0 flex-1 text-[13.5px]">
                    {#if link.warning.reason === 'idle'}
                        <p class="m-0 font-medium">
                            Nothing typed for {Math.round(terminal.idle_seconds / 60) - 1} minutes, so this session ends in
                            {inText(remaining)}.
                        </p>
                        <p class="m-0 mt-0.5 text-[12.5px] text-muted-foreground">
                            Output doesn't keep it open. Anything still running stops unless it's in tmux, screen or
                            nohup.
                        </p>
                    {:else}
                        <p class="m-0 font-medium">This session reaches its hour in {inText(remaining)}, then ends.</p>
                        <p class="m-0 mt-0.5 text-[12.5px] text-muted-foreground">
                            Open a new session to carry on. Anything still running stops unless it's in tmux, screen or
                            nohup.
                        </p>
                    {/if}
                </div>
                {#if link.warning.reason === 'idle'}
                    <Button size="sm" onclick={() => void link.keep().then(() => shell?.focus())}>Keep it open</Button>
                {/if}
            </div>
        {/if}

        {#if finding}
            <div class="flex flex-wrap items-center gap-2" role="search" data-testid="terminal-find">
                <label for="terminal-find" class="sr-only">Find in the terminal</label>
                <Input
                    id="terminal-find"
                    bind:ref={findInput}
                    bind:value={query}
                    oninput={() => find(true)}
                    onkeydown={findKey}
                    placeholder="Find in the terminal"
                    class="h-9 max-w-72 max-sm:h-11"
                    aria-invalid={missing ? true : undefined}
                    aria-describedby="terminal-find-result"
                />
                <Button variant="outline" size="sm" onclick={() => find(false)}>Previous</Button>
                <Button variant="outline" size="sm" onclick={() => find(true)}>Next</Button>
                <Button variant="ghost" size="sm" onclick={closeFind} aria-label="Close find">
                    <X class="size-4" aria-hidden="true" />
                </Button>
                <span id="terminal-find-result" class="text-[12.5px] text-muted-foreground" aria-live="polite"
                    >{missing ? 'No match' : ''}</span
                >
            </div>
        {/if}

        {#if link.state === 'offline'}
            <p class="tint-destructive m-0 rounded-xl border px-4.5 py-3 text-[13.5px]" role="alert">
                The live connection to Coresmith isn't running, so this terminal can't show anything. Reload the page
                once it's back.
            </p>
        {/if}

        <div
            class={cn(
                'terminal overflow-hidden p-2',
                full
                    ? 'min-h-0 flex-1'
                    : 'h-[calc(100dvh-17rem)] min-h-72 max-sm:h-[calc(100dvh-22rem)] max-sm:min-h-56'
            )}
        >
            <Shell
                label="Shell on {server.name}, as {terminal.as}"
                ontype={(text) => link.type(text)}
                onsize={(cols, rows) => link.resize(cols, rows)}
                onpasted={paste}
                {ready}
            />
        </div>

        <div class="hidden flex-wrap gap-1.5 max-sm:flex" role="group" aria-label="Keys" data-testid="terminal-keys">
            {#each KEYS as item (item.label)}
                <button
                    type="button"
                    class={cn(
                        'focus-ring inline-flex h-11 min-w-11 items-center justify-center rounded-md border border-border bg-card px-3 font-mono text-[13px]',
                        item.send === 'ctrl' && ctrl && 'option-selected'
                    )}
                    aria-label={item.name}
                    aria-pressed={item.send === 'ctrl' ? ctrl : undefined}
                    onclick={() => key(item.send)}>{item.label}</button
                >
            {/each}
            <button
                type="button"
                class="focus-ring inline-flex h-11 items-center justify-center rounded-md border border-border bg-card px-3 text-[13px]"
                onclick={pasteFromClipboard}>Paste</button
            >
        </div>

        <p class="m-0 text-[12.5px] text-muted-foreground">
            Ends on its own after 10 minutes without typing, after an hour, or when you leave this page. Start long jobs
            in tmux, screen or nohup so they keep running. Coresmith keeps no record of what you type.
        </p>
    </section>
{/if}

<Dialog.Root open={pasting !== null} onOpenChange={(open) => !open && ((pasting = null), shell?.focus())}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Paste {pasteLines.length} lines into the shell?</Dialog.Title>
            <Dialog.Description>Each line runs as you'd typed it, one after another.</Dialog.Description>
        </Dialog.Header>
        <pre
            class="terminal m-0 max-h-48 overflow-auto p-3 text-[12px] whitespace-pre-wrap"
            data-testid="terminal-paste-preview">{pasteLines.slice(0, 8).join('\n')}{pasteLines.length > 8
                ? `\n… and ${pasteLines.length - 8} more`
                : ''}</pre>
        <Dialog.Footer>
            <Button variant="outline" onclick={() => ((pasting = null), shell?.focus())}>Cancel</Button>
            <Button onclick={confirmPaste}>Paste</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={ending}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>End this session?</Dialog.Title>
            <Dialog.Description>
                Anything still running in it stops, unless it's in tmux, screen or nohup.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="outline" onclick={() => (ending = false)}>Keep working</Button>
            <Button variant="destructive" onclick={end} data-testid="terminal-end-confirm">End session</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
