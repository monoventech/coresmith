import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

declare global {
    interface Window {
        Pusher: typeof Pusher
    }
}

let connection: Echo<'reverb'> | null = null

export function realtimeConfigured(): boolean {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY)
}

export function realtime(): Echo<'reverb'> | null {
    if (!realtimeConfigured()) {
        return null
    }

    if (connection) {
        return connection
    }

    window.Pusher = Pusher

    connection = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss']
    })

    return connection
}
