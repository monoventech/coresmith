// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.
import { notify } from '@/lib/toast.svelte'
import { router } from '@inertiajs/svelte'

export type UpdateRunState =
    'requested' | 'starting' | 'running' | 'succeeded' | 'aborted' | 'rolled_back' | 'failed' | 'interrupted'

export type UpdateRun = {
    id: string | null
    state: UpdateRunState
    from: string | null
    to: string | null
    step: string | null
    label: string | null
    number: number | null
    total: number
    started: string | null
    duration: string | null
    message: string | null
    failed_label: string | null
    log: string[]
    log_href: string | null
    stalled: boolean
}

export type PanelUpdates = {
    mode: 'self-hosted' | 'cloud' | 'unmanaged'
    current: string
    channel: string
    line: string
    badge: { label: string; tone: 'default' | 'accent' | 'success' | 'warning' | 'destructive' } | null
    check: { enabled: boolean; when: string | null; error: string | null }
    available: { version: string; summary: string | null; notes_url: string | null; released: string | null } | null
    notices: { tone: 'warning' | 'muted'; text: string }[]
    auto: boolean
    managed: boolean
    run: UpdateRun | null
    status_href: string
}

const ACTIVE: UpdateRunState[] = ['requested', 'starting', 'running']

const FAILED: UpdateRunState[] = ['aborted', 'rolled_back', 'failed', 'interrupted']

export function isActive(run: UpdateRun | null): boolean {
    return run !== null && ACTIVE.includes(run.state)
}

export function hasFailed(run: UpdateRun | null): boolean {
    return run !== null && FAILED.includes(run.state)
}

export function createUpdatePoller(href: string, initial: UpdateRun | null) {
    const state = $state<{ run: UpdateRun | null; restarting: boolean }>({ run: initial, restarting: false })
    let timer: ReturnType<typeof setTimeout> | undefined
    let polling = false

    function schedule(milliseconds: number): void {
        clearTimeout(timer)
        timer = setTimeout(() => void poll(), milliseconds)
    }

    async function poll(): Promise<void> {
        if (!polling) {
            return
        }

        if (document.visibilityState === 'hidden') {
            schedule(2000)

            return
        }

        try {
            const response = await fetch(href, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })

            if (!response.ok) {
                throw new Error(`The status answered ${response.status}.`)
            }

            const body = (await response.json()) as { run: UpdateRun | null }

            if (!polling) {
                return
            }

            state.run = body.run
            state.restarting = false

            if (body.run?.state === 'succeeded') {
                polling = false
                notify(
                    'Updated.',
                    `Coresmith v${body.run.to} is running. Backup taken, health check passed.`,
                    'success'
                )
                router.reload()

                return
            }

            if (hasFailed(body.run)) {
                polling = false
                router.reload({ only: ['updates'] })

                return
            }
        } catch {
            state.restarting = true
        }

        if (state.restarting || isActive(state.run)) {
            schedule(state.restarting ? 5000 : 2000)
        } else {
            polling = false
        }
    }

    return {
        get run(): UpdateRun | null {
            return state.run
        },
        get restarting(): boolean {
            return state.restarting
        },
        sync(run: UpdateRun | null): void {
            state.run = run
            state.restarting = false

            if (isActive(run)) {
                this.start()
            }
        },
        start(): void {
            if (polling) {
                return
            }

            polling = true
            schedule(2000)
        },
        stop(): void {
            polling = false
            clearTimeout(timer)
        }
    }
}
