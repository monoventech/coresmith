// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type CronPresetKind = 'minute' | 'hourly' | 'daily' | 'weekly' | 'custom'

export type CronPreset = { kind: CronPresetKind; minute: number; hour: number; weekday: number }

export type CronJob = {
    id: string
    schedule: string
    command: string
    command_summary: string
    description: string | null
    title: string
    title_is_command: boolean
    sentence: string | null
    preset: CronPreset
    scheduler: boolean
    href: string
}

export type CronCard = {
    jobs: CronJob[]
    limit: number
    state: 'applying' | 'waiting' | null
    note: 'paused' | 'undeployed' | null
    error: string | null
    live: boolean
    store_href: string
    sync_href: string
    log_href: string
}

export type CronSend = (visit: (finish: () => void) => void) => void

export const RUNS: { value: CronPresetKind; label: string }[] = [
    { value: 'minute', label: 'Every minute' },
    { value: 'hourly', label: 'Hourly' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'custom', label: 'Custom expression' }
]

export const WEEKDAYS: { value: string; label: string }[] = [
    { value: '1', label: 'Monday' },
    { value: '2', label: 'Tuesday' },
    { value: '3', label: 'Wednesday' },
    { value: '4', label: 'Thursday' },
    { value: '5', label: 'Friday' },
    { value: '6', label: 'Saturday' },
    { value: '0', label: 'Sunday' }
]

export function cronExpression(
    kind: CronPresetKind,
    minute: number,
    hour: number,
    weekday: number,
    custom: string
): string {
    switch (kind) {
        case 'minute':
            return '* * * * *'
        case 'hourly':
            return `${minute} * * * *`
        case 'daily':
            return `${minute} ${hour} * * *`
        case 'weekly':
            return `${minute} ${hour} * * ${weekday}`
        default:
            return custom.trim()
    }
}

export function clock(hour: number, minute: number): string {
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`
}

export function readClock(value: string): { hour: number; minute: number } | null {
    const match = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(value.trim())

    if (!match) {
        return null
    }

    const hour = Number(match[1])
    const minute = Number(match[2])

    return hour <= 23 && minute <= 59 ? { hour, minute } : null
}

export function readMinute(value: string | number | null | undefined): number | null {
    const text = String(value ?? '').trim()

    return /^\d{1,2}$/.test(text) && Number(text) <= 59 ? Number(text) : null
}
