// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import { realtime, realtimeConfigured } from '@/lib/echo'
import type { PanelProps } from '@/lib/panel'
import { page, router } from '@inertiajs/svelte'

export type FollowOptions = { only: string[]; paused?: () => boolean }

const DEBOUNCE_MS = 1500

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

        router.reload({ only: options.only })
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

    const ticker = setInterval(reload, seconds * 1000)
    const echo = channel !== null && panel?.live.streaming && realtimeConfigured() ? realtime() : null

    document.addEventListener('visibilitychange', visibility)

    try {
        echo?.private(channel as string).listen('.fleet.changed', soon)
    } catch {
        echo?.leave(channel as string)
    }

    return () => {
        clearInterval(ticker)

        if (pending !== null) {
            clearTimeout(pending)
        }

        document.removeEventListener('visibilitychange', visibility)

        if (echo !== null && channel !== null) {
            echo.leave(channel)
        }
    }
}
