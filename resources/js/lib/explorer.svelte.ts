// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

const WIDE = '(min-width: 1280px)'
const HIDDEN_KEY = 'explorer'
const FOLDED_KEY = 'explorer-folded'

function read(key: string): string | null {
    try {
        return localStorage.getItem(key)
    } catch {
        return null
    }
}

function write(key: string, value: string): void {
    try {
        localStorage.setItem(key, value)
    } catch {
        return
    }
}

function folded(): string[] {
    try {
        const value = JSON.parse(read(FOLDED_KEY) ?? '[]')

        return Array.isArray(value) ? value.filter((id): id is string => typeof id === 'string') : []
    } catch {
        return []
    }
}

const browser = typeof window !== 'undefined'

const state = $state<{ wide: boolean; hidden: boolean; open: boolean; folded: string[] }>({
    wide: browser ? window.matchMedia(WIDE).matches : true,
    hidden: browser && read(HIDDEN_KEY) === 'hidden',
    open: false,
    folded: browser ? folded() : []
})

let query: MediaQueryList | null = null

export function initializeExplorer(): void {
    if (!browser || query) {
        return
    }

    query = window.matchMedia(WIDE)
    query.addEventListener('change', () => {
        state.wide = query?.matches ?? true
        state.open = false
    })
}

export function explorerState(): { wide: boolean; hidden: boolean; open: boolean; folded: string[] } {
    return state
}

export function explorerInline(): boolean {
    return state.wide && !state.hidden
}

export function showExplorer(): void {
    if (state.wide) {
        state.hidden = false
        write(HIDDEN_KEY, 'shown')

        return
    }

    state.open = true
}

export function hideExplorer(): void {
    if (state.wide) {
        state.hidden = true
        write(HIDDEN_KEY, 'hidden')

        return
    }

    state.open = false
}

export function closeExplorer(): void {
    state.open = false
}

export function toggleProject(id: string): void {
    state.folded = state.folded.includes(id) ? state.folded.filter((each) => each !== id) : [...state.folded, id]
    write(FOLDED_KEY, JSON.stringify(state.folded))
}

export function elapsedSince(iso: string | null, now: number): string | null {
    if (iso === null) {
        return null
    }

    const seconds = Math.max(0, Math.floor((now - Date.parse(iso)) / 1000))
    const hours = Math.floor(seconds / 3600)
    const minutes = Math.floor((seconds % 3600) / 60)

    if (hours > 0) {
        return `${hours}h ${minutes}m`
    }

    return minutes > 0 ? `${minutes}m ${seconds % 60}s` : `${seconds}s`
}
