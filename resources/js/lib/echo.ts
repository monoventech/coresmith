import type { PanelProps } from '@/lib/panel'
import { page } from '@inertiajs/svelte'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

declare global {
    interface Window {
        Pusher: typeof Pusher
    }
}

let connection: Echo<'reverb'> | null = null

function socket(): PanelProps['live']['socket'] {
    return (page.props.panel as PanelProps | null)?.live.socket ?? null
}

export function realtimeConfigured(): boolean {
    return socket() !== null
}

export function realtime(): Echo<'reverb'> | null {
    const settings = socket()

    if (settings === null) {
        return null
    }

    if (connection) {
        return connection
    }

    window.Pusher = Pusher

    connection = new Echo({
        broadcaster: 'reverb',
        key: settings.key,
        wsHost: settings.host,
        wsPort: settings.port,
        wssPort: settings.port,
        forceTLS: settings.tls,
        enabledTransports: ['ws', 'wss']
    })

    return connection
}
