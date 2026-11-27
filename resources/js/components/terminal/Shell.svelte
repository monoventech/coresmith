<script module lang="ts">
    export type ShellApi = {
        write(bytes: Uint8Array): void
        reset(): void
        focus(): void
        type(text: string): void
        paste(text: string): void
        copy(): Promise<boolean>
        find(term: string, forward?: boolean): boolean
        clearFind(): void
    }

    export const THEME = {
        background: '#100c0a',
        foreground: '#d4d0cb',
        cursor: '#ffac7b',
        cursorAccent: '#100c0a',
        selectionBackground: '#624727',
        black: '#100c0a',
        brightBlack: '#898680',
        red: '#ed756e',
        brightRed: '#ff9b93',
        green: '#62bb78',
        brightGreen: '#89e29d',
        yellow: '#eeb154',
        brightYellow: '#ffcb6f',
        blue: '#6dabdf',
        brightBlue: '#92cbfb',
        magenta: '#cf88c8',
        brightMagenta: '#e9ade3',
        cyan: '#6ac9ce',
        brightCyan: '#90e1e6',
        white: '#d4d0cb',
        brightWhite: '#f3f1ee'
    }
</script>

<script lang="ts">
    import { FitAddon } from '@xterm/addon-fit'
    import { SearchAddon } from '@xterm/addon-search'
    import { Unicode11Addon } from '@xterm/addon-unicode11'
    import { WebLinksAddon } from '@xterm/addon-web-links'
    import { Terminal } from '@xterm/xterm'
    import '@xterm/xterm/css/xterm.css'
    import { onDestroy, onMount } from 'svelte'

    let {
        label,
        disabled = false,
        ontype,
        onsize,
        onpasted,
        ready
    }: {
        label: string
        disabled?: boolean
        ontype: (text: string) => void
        onsize: (cols: number, rows: number) => void
        onpasted: (text: string) => void
        ready: (api: ShellApi) => void
    } = $props()

    let host = $state<HTMLDivElement | null>(null)
    let terminal: Terminal | null = null
    let fit: FitAddon | null = null
    let search: SearchAddon | null = null
    let observer: ResizeObserver | null = null
    let ctrl = false

    const ARROWS = ['\x1b[A', '\x1b[B', '\x1b[C', '\x1b[D']

    function fitNow() {
        try {
            fit?.fit()
        } catch {
            return
        }
    }

    function interceptPaste(event: ClipboardEvent) {
        const text = event.clipboardData?.getData('text/plain') ?? ''

        event.preventDefault()
        event.stopPropagation()

        if (text !== '') {
            onpasted(text)
        }
    }

    onMount(() => {
        if (!host) {
            return
        }

        terminal = new Terminal({
            allowProposedApi: true,
            cursorBlink: false,
            cursorStyle: 'block',
            fontFamily: "'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace",
            fontSize: 13,
            lineHeight: 1.25,
            scrollback: 5000,
            theme: THEME,
            minimumContrastRatio: 4.5
        })

        fit = new FitAddon()
        search = new SearchAddon()
        terminal.loadAddon(fit)
        terminal.loadAddon(search)
        terminal.loadAddon(new WebLinksAddon())
        terminal.loadAddon(new Unicode11Addon())
        terminal.unicode.activeVersion = '11'
        terminal.open(host)
        terminal.textarea?.setAttribute('aria-label', label)
        host.addEventListener('paste', interceptPaste, true)

        terminal.onData((data) => {
            if (disabled) {
                return
            }

            if (ctrl && data.length === 1 && /[a-z@[\\\]^_]/i.test(data)) {
                ctrl = false
                ontype(String.fromCharCode(data.toUpperCase().charCodeAt(0) & 0x1f))

                return
            }

            ctrl = false
            ontype(data)
        })
        terminal.onResize(({ cols, rows }) => onsize(cols, rows))
        terminal.onSelectionChange(() => {
            const text = terminal?.getSelection() ?? ''

            if (text !== '') {
                void navigator.clipboard?.writeText(text).catch(() => undefined)
            }
        })

        observer = new ResizeObserver(() => fitNow())
        observer.observe(host)
        fitNow()
        onsize(terminal.cols, terminal.rows)

        const current = terminal

        ready({
            write: (bytes) => current.write(bytes),
            reset: () => current.reset(),
            focus: () => current.focus(),
            type: (text) => {
                if (text === 'ctrl') {
                    ctrl = !ctrl
                    current.focus()

                    return
                }

                ontype(
                    current.modes.applicationCursorKeysMode && ARROWS.includes(text) ? `\x1bO${text.slice(2)}` : text
                )
                current.focus()
            },
            paste: (text) => {
                current.paste(text)
                current.focus()
            },
            copy: async () => {
                const text = current.getSelection()

                if (text === '') {
                    return false
                }

                try {
                    await navigator.clipboard.writeText(text)

                    return true
                } catch {
                    return false
                }
            },
            find: (term, forward = true) =>
                term === ''
                    ? false
                    : forward
                      ? (search?.findNext(term, { incremental: false }) ?? false)
                      : (search?.findPrevious(term) ?? false),
            clearFind: () => {
                search?.clearDecorations()
                current.clearSelection()
            }
        })
    })

    onDestroy(() => {
        observer?.disconnect()
        host?.removeEventListener('paste', interceptPaste, true)
        terminal?.dispose()
        terminal = null
    })
</script>

<div bind:this={host} class="terminal-shell h-full w-full" data-testid="terminal-shell"></div>
