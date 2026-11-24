// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export const REVEAL_MS = 320

const MIN_CHARACTERS_PER_MS = 0.12

function instant(): boolean {
    return (
        typeof window === 'undefined' ||
        document.visibilityState === 'hidden' ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    )
}

export class Reveal {
    lines = $state<string[]>([])
    revealing = $state(false)
    settled = $state(0)

    #text = ''
    #pending = ''
    #cursor = 0
    #rate = 0
    #budget = 0
    #last = 0
    #frame = 0

    constructor(text = '') {
        this.reset(text)
    }

    get text(): string {
        return this.#text
    }

    reset(text: string): void {
        this.#stop()
        this.#text = text
        this.#pending = ''
        this.#cursor = 0

        this.lines = text === '' ? [] : text.split('\n')
        this.settled = this.lines.length
    }

    push(text: string): void {
        if (text === '') {
            return
        }

        this.#text += text

        if (instant()) {
            this.#show(this.#pending.slice(this.#cursor) + text)
            this.#pending = ''
            this.#cursor = 0
            this.#stop()

            return
        }

        this.#pending = this.#pending.slice(this.#cursor) + text
        this.#cursor = 0
        this.#rate = Math.max(MIN_CHARACTERS_PER_MS, this.#pending.length / REVEAL_MS)
        this.#start()
    }

    destroy(): void {
        this.#stop()
    }

    #start(): void {
        if (this.#frame !== 0) {
            return
        }

        this.revealing = true
        this.#last = performance.now()
        this.#frame = requestAnimationFrame((now) => this.#step(now))
    }

    #step(now: number): void {
        this.#budget += this.#rate * Math.max(1, now - this.#last)
        this.#last = now

        const left = this.#pending.length - this.#cursor
        let take = Math.min(left, Math.max(1, Math.floor(this.#budget)))
        const last = this.#pending.charCodeAt(this.#cursor + take - 1)

        if (last >= 0xd800 && last <= 0xdbff && take < left) {
            take++
        }

        this.#budget = Math.max(0, this.#budget - take)
        this.#show(this.#pending.slice(this.#cursor, this.#cursor + take))
        this.#cursor += take

        if (this.#cursor >= this.#pending.length) {
            this.#pending = ''
            this.#cursor = 0
            this.#frame = 0
            this.#budget = 0
            this.revealing = false

            return
        }

        this.#frame = requestAnimationFrame((next) => this.#step(next))
    }

    #show(text: string): void {
        const parts = text.split('\n')

        if (this.lines.length === 0) {
            this.lines.push(parts.shift() ?? '')
        } else {
            this.lines[this.lines.length - 1] += parts.shift() ?? ''
        }

        for (let start = 0; start < parts.length; start += 1000) {
            this.lines.push(...parts.slice(start, start + 1000))
        }
    }

    #stop(): void {
        if (this.#frame !== 0) {
            cancelAnimationFrame(this.#frame)
            this.#frame = 0
        }

        this.revealing = false
        this.#budget = 0
    }
}
