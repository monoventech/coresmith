// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import { REFRESH_HEADERS } from '@/lib/visits'

export const GAP_MS = 1000

export type LogDelta<Status> = { from: number; text: string; bytes: number; deployment: Status }

type Options<Status> = {
    href: string
    held: number
    onText: (text: string) => void
    onStatus: (status: Status) => void
}

export class LogFeed<Status> {
    held: number

    #href: string
    #onText: (text: string) => void
    #onStatus: (status: Status) => void
    #stash = new Map<number, string>()
    #target = 0
    #timer: ReturnType<typeof setTimeout> | null = null
    #fetching = false
    #resting = 0
    #closed = false
    #encoder = new TextEncoder()
    #decoder = new TextDecoder()

    constructor(options: Options<Status>) {
        this.#href = options.href
        this.held = options.held
        this.#target = options.held
        this.#onText = options.onText
        this.#onStatus = options.onStatus
    }

    receive(offset: number, text: string): void {
        if (this.#accept(offset, text)) {
            this.#drain()
        }
    }

    behind(bytes: number, wait = GAP_MS): void {
        this.#target = Math.max(this.#target, bytes)
        this.#later(wait)
    }

    async fill(): Promise<void> {
        if (this.#fetching || this.#closed) {
            return
        }

        if (Date.now() < this.#resting) {
            this.#later(0)

            return
        }

        this.#fetching = true

        const before = this.held
        const aimed = this.#target

        try {
            for (let reads = 0; reads < 20; reads++) {
                const url = new URL(this.#href, window.location.href)
                url.searchParams.set('from', String(this.held))

                const response = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...REFRESH_HEADERS }
                })

                if (response.status === 429) {
                    this.#resting = Date.now() + Number(response.headers.get('Retry-After') ?? 10) * 1000

                    return
                }

                if (!response.ok || this.#closed) {
                    return
                }

                const delta = (await response.json()) as LogDelta<Status>

                this.#target = Math.max(this.#target, delta.bytes)
                this.receive(delta.from, delta.text)
                this.#onStatus(delta.deployment)

                if (this.held >= delta.bytes || delta.text === '') {
                    return
                }
            }
        } catch {
            return
        } finally {
            this.#fetching = false

            if (this.held > before || this.#target > aimed || Date.now() < this.#resting) {
                this.#later(GAP_MS)
            }
        }
    }

    close(): void {
        this.#closed = true

        if (this.#timer !== null) {
            clearTimeout(this.#timer)
            this.#timer = null
        }
    }

    #later(wait: number): void {
        if (this.held >= this.#target || this.#timer !== null || this.#closed) {
            return
        }

        this.#timer = setTimeout(
            () => {
                this.#timer = null

                if (this.held < this.#target) {
                    void this.fill()
                }
            },
            Math.max(wait, this.#resting - Date.now())
        )
    }

    #accept(offset: number, text: string): boolean {
        if (offset > this.held) {
            this.#stash.set(offset, text)
            this.behind(offset)

            return false
        }

        const bytes = this.#encoder.encode(text)
        const end = offset + bytes.length

        if (end <= this.held) {
            return true
        }

        const fresh = offset === this.held ? text : this.#decoder.decode(bytes.subarray(this.held - offset))

        this.held = end
        this.#onText(fresh)

        return true
    }

    #drain(): void {
        for (let progressed = true; progressed;) {
            progressed = false

            for (const [offset, text] of this.#stash) {
                if (offset <= this.held) {
                    this.#stash.delete(offset)
                    this.#accept(offset, text)
                    progressed = true
                }
            }
        }
    }
}
