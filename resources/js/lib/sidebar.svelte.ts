const NARROW = '(max-width: 899px)'
const STORAGE_KEY = 'sidebar'

const state = $state<{ collapsed: boolean; manual: boolean }>({ collapsed: false, manual: false })

let query: MediaQueryList | null = null

function stored(): 'collapsed' | 'expanded' | null {
    try {
        const value = localStorage.getItem(STORAGE_KEY)

        return value === 'collapsed' || value === 'expanded' ? value : null
    } catch {
        return null
    }
}

function sync(): void {
    if (!query) {
        return
    }

    if (query.matches) {
        state.collapsed = true

        return
    }

    const choice = stored()
    state.manual = choice !== null
    state.collapsed = choice === 'collapsed'
}

export function initializeSidebar(): void {
    if (typeof window === 'undefined' || query) {
        return
    }

    query = window.matchMedia(NARROW)
    query.addEventListener('change', sync)
    sync()
}

export function toggleSidebar(): void {
    state.collapsed = !state.collapsed
    state.manual = true

    try {
        localStorage.setItem(STORAGE_KEY, state.collapsed ? 'collapsed' : 'expanded')
    } catch {
        return
    }
}

export function sidebarState(): { collapsed: boolean; manual: boolean } {
    return state
}
