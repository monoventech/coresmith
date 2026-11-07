export type ToastTone = 'default' | 'success' | 'warning' | 'destructive'

export type ToastMessage = {
    id: number
    title: string
    description?: string
    tone: ToastTone
}

export const TOAST_MS = 4800

const state = $state<{ current: ToastMessage | null }>({ current: null })

let timer: ReturnType<typeof setTimeout> | undefined
let sequence = 0

export function notify(title: string, description?: string, tone: ToastTone = 'default'): void {
    clearTimeout(timer)

    const id = ++sequence

    state.current = { id, title, description, tone }

    timer = setTimeout(() => {
        if (state.current?.id === id) {
            state.current = null
        }
    }, TOAST_MS)
}

export function dismissToast(): void {
    clearTimeout(timer)
    state.current = null
}

export function toastState(): { current: ToastMessage | null } {
    return state
}

export function splitMessage(message: string): { title: string; description?: string } {
    const match = message.match(/^(.+?[.!?])\s+(.+)$/s)

    return match ? { title: match[1], description: match[2] } : { title: message }
}
