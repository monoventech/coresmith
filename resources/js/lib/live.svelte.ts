// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import { realtime, realtimeConfigured } from '@/lib/echo'
import type { PanelProps } from '@/lib/panel'
import { page, router } from '@inertiajs/svelte'

export type FollowOptions = { only: string[]; paused?: () => boolean; async?: boolean; shell?: boolean }

const DEBOUNCE_MS = 1500

const listening: Record<string, number> = {}

const SHARED = ['explorer', 'panel']

let pages = 0

export function follow(options: FollowOptions): () => void {
    const panel = page.props.panel as PanelProps | null
    const seconds = Math.max(1, panel?.live.refresh_seconds ?? 60)
    const team = panel?.team?.id ?? null
    const channel = team === null ? null : `teams.${team}`
    let pending: ReturnType<typeof setTimeout> | null = null
    let hiddenAt: number | null = null

    function held(): boolean {
        return (
            document.visibilityState !== 'visible' ||
            document.querySelector('[role="dialog"]') !== null ||
            options.paused?.() === true
        )
    }

    function reload() {
        if (held()) {
            return
        }

        if (options.shell && pages > 0) {
            return
        }

        router.reload({
            only: options.shell
                ? options.only
                : [...options.only, ...SHARED.filter((key) => !options.only.includes(key))],
            async: options.async ?? false
        })
    }

    function soon() {
        if (pending !== null) {
            clearTimeout(pending)
        }

        pending = setTimeout(() => {
            pending = null
            reload()
        }, DEBOUNCE_MS)
    }

    function visibility() {
        if (document.visibilityState !== 'visible') {
            hiddenAt = Date.now()

            return
        }

        if (hiddenAt !== null && Date.now() - hiddenAt > seconds * 1000) {
            reload()
        }

        hiddenAt = null
    }

    if (!options.shell) {
        pages += 1
    }

    const ticker = setInterval(reload, seconds * 1000)
    const echo = channel !== null && panel?.live.streaming && realtimeConfigured() ? realtime() : null

    document.addEventListener('visibilitychange', visibility)

    try {
        if (echo !== null && channel !== null) {
            echo.private(channel).listen('.fleet.changed', soon)
            listening[channel] = (listening[channel] ?? 0) + 1
        }
    } catch {
        echo?.leave(channel as string)
    }

    return () => {
        clearInterval(ticker)

        if (!options.shell) {
            pages -= 1
        }

        if (pending !== null) {
            clearTimeout(pending)
        }

        document.removeEventListener('visibilitychange', visibility)

        if (echo !== null && channel !== null && listening[channel] !== undefined) {
            echo.private(channel).stopListening('.fleet.changed', soon)
            const left = (listening[channel] ?? 1) - 1

            if (left > 0) {
                listening[channel] = left
            } else {
                delete listening[channel]
                echo.leave(channel)
            }
        }
    }
}
