import { realtime, realtimeConfigured } from '@/lib/echo'

export type Change = { key: string; title: string; detail: string }
export type Ended = {
    reason: string | null
    sentence: string | null
    minutes: number
    changes: Change[]
    checking: boolean
    opened: boolean
}
export type Links = { input: string; resize: string; check_in: string; screen: string; end: string; again: string }
export type LinkState = 'connecting' | 'connected' | 'reconnecting' | 'ended' | 'offline'
export type Warning = { reason: 'idle' | 'hour'; endsAt: number }
export type Screen = { write(bytes: Uint8Array): void; reset(): void }

type Status = { status: string; ended: Ended | null }
type Saved = Status & { seq: number; screen: string }
type Socket = { bind(event: string, handler: (change: { current: string }) => void): void }

const CHECK_IN_MS = 10_000
const GAP_MS = 400
const RESIZE_MS = 150
const INPUT_CHARS = 2048

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

    return match ? decodeURIComponent(match[1]) : ''
}

export function send(url: string, method: 'GET' | 'POST' | 'DELETE', body?: unknown): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf()
        },
        body: body === undefined ? undefined : JSON.stringify(body)
    })
}

export function encode(text: string): string {
    const bytes = new TextEncoder().encode(text)
    let binary = ''

    for (let index = 0; index < bytes.length; index += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(index, index + 0x8000))
    }

    return btoa(binary)
}

export function decode(data: string): Uint8Array {
    return Uint8Array.from(atob(data), (character) => character.charCodeAt(0))
}

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms))
}

function randomId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
}

export class TerminalLink {
    state = $state<LinkState>('connecting')
    ended = $state<Ended | null>(null)
    warning = $state<Warning | null>(null)

    private readonly from = randomId()
    private n = 0
    private queued = ''
    private sending = false
    private seq = 0
    private held: Record<number, Uint8Array> = {}
    private gapTimer: ReturnType<typeof setTimeout> | null = null
    private resizeTimer: ReturnType<typeof setTimeout> | null = null
    private checkTimer: ReturnType<typeof setInterval> | null = null
    private channel: string | null = null
    private closed = false

    constructor(
        private readonly id: string,
        private readonly links: Links,
        private readonly screen: Screen
    ) {}

    start(): void {
        const echo = realtimeConfigured() ? realtime() : null

        if (!echo) {
            this.state = 'offline'
            void this.sync()

            return
        }

        this.channel = `terminals.${this.id}`

        echo.private(this.channel)
            .listen('.terminal.output', (event: { seq: number; data: string }) => this.receive(event.seq, event.data))
            .listen('.terminal.warning', (event: { reason: 'idle' | 'hour'; ends_at: number }) => {
                this.warning = { reason: event.reason, endsAt: event.ends_at }
            })
            .listen('.terminal.ended', () => void this.sync())
            .subscribed(() => void this.sync().then(() => this.connected()))
            .error(() => this.lost())

        const socket = (echo.connector as { pusher?: { connection?: Socket } }).pusher?.connection

        socket?.bind('state_change', (change) => {
            if (change.current === 'connected') {
                if (this.state === 'reconnecting') {
                    void this.sync().then(() => this.connected())
                }
            } else {
                this.lost()
            }
        })

        this.checkTimer = setInterval(() => void this.checkIn(), CHECK_IN_MS)
        document.addEventListener('visibilitychange', this.onVisibility)
        void this.checkIn()
    }

    stop(): void {
        this.closed = true

        if (this.checkTimer) {
            clearInterval(this.checkTimer)
        }

        if (this.gapTimer) {
            clearTimeout(this.gapTimer)
        }

        if (this.resizeTimer) {
            clearTimeout(this.resizeTimer)
        }

        document.removeEventListener('visibilitychange', this.onVisibility)

        if (this.channel) {
            realtime()?.leave(this.channel)
            this.channel = null
        }
    }

    type(text: string): void {
        if (this.ended || text === '') {
            return
        }

        this.queued += text
        void this.flush()
    }

    resize(cols: number, rows: number): void {
        if (this.resizeTimer) {
            clearTimeout(this.resizeTimer)
        }

        this.resizeTimer = setTimeout(() => {
            this.resizeTimer = null

            if (!this.ended) {
                void send(this.links.resize, 'POST', { cols, rows }).catch(() => undefined)
            }
        }, RESIZE_MS)
    }

    async keep(): Promise<void> {
        this.warning = null
        await this.checkIn(true)
    }

    async end(): Promise<void> {
        try {
            const response = await send(this.links.end, 'DELETE')

            if (response.ok) {
                this.apply((await response.json()) as Status)
            }
        } catch {
            this.lost()
        }
    }

    async sync(): Promise<void> {
        try {
            const response = await send(this.links.screen, 'GET')

            if (!response.ok) {
                return
            }

            const saved = (await response.json()) as Saved

            if (saved.seq >= this.seq) {
                this.screen.reset()
                this.screen.write(decode(saved.screen))
                this.seq = saved.seq

                for (const seq of Object.keys(this.held).map(Number)) {
                    if (seq <= this.seq) {
                        delete this.held[seq]
                    }
                }

                this.drain()
            }

            this.apply(saved)
        } catch {
            this.lost()
        }
    }

    private readonly onVisibility = (): void => {
        void this.checkIn()
    }

    private connected(): void {
        if (!this.ended && !this.closed) {
            this.state = 'connected'
        }
    }

    private lost(): void {
        if (!this.ended && !this.closed && this.state !== 'offline') {
            this.state = 'reconnecting'
        }
    }

    private receive(seq: number, data: string): void {
        if (seq <= this.seq) {
            return
        }

        this.held[seq] = decode(data)
        this.drain()

        if (this.waiting() && !this.gapTimer) {
            this.gapTimer = setTimeout(() => {
                this.gapTimer = null

                if (this.waiting()) {
                    void this.sync()
                }
            }, GAP_MS)
        }
    }

    private waiting(): boolean {
        return Object.keys(this.held).length > 0
    }

    private drain(): void {
        let next = this.held[this.seq + 1]

        while (next) {
            delete this.held[this.seq + 1]
            this.seq++
            this.screen.write(next)
            next = this.held[this.seq + 1]
        }
    }

    private async checkIn(keep = false): Promise<void> {
        if (this.ended || this.closed) {
            return
        }

        try {
            const response = await send(this.links.check_in, 'POST', { keep, hidden: document.hidden })

            if (response.ok) {
                this.apply((await response.json()) as Status)
            }
        } catch {
            this.lost()
        }
    }

    private async flush(): Promise<void> {
        if (this.sending || this.queued === '' || this.closed) {
            return
        }

        this.sending = true

        let size = Math.min(this.queued.length, INPUT_CHARS)
        const last = this.queued.charCodeAt(size - 1)

        if (size < this.queued.length && last >= 0xd800 && last <= 0xdbff) {
            size--
        }

        const body = { id: this.from, n: ++this.n, data: encode(this.queued.slice(0, size)) }
        let delay = 250

        this.queued = this.queued.slice(size)

        while (!this.closed && !this.ended) {
            try {
                const response = await send(this.links.input, 'POST', body)

                if (response.status === 429 || response.status >= 500) {
                    throw new Error(String(response.status))
                }

                if (response.ok) {
                    this.apply((await response.json()) as Status)
                }

                break
            } catch {
                await wait(delay)
                delay = Math.min(delay * 2, 4000)
            }
        }

        this.sending = false
        void this.flush()
    }

    private apply(status: Status): void {
        if (status.ended) {
            this.ended = status.ended
            this.warning = null
            this.state = 'ended'
            this.stop()
        }
    }
}
