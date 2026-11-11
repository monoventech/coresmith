import AccountLayout from '@/layouts/account/Layout.svelte'
import AppLayout from '@/layouts/AppLayout.svelte'
import AuthLayout from '@/layouts/AuthLayout.svelte'
import SettingsLayout from '@/layouts/settings/Layout.svelte'
import { initializeFlashToast } from '@/lib/flash-toast'
import { startSentry } from '@/lib/sentry'
import { initializeTheme } from '@/lib/theme.svelte'
import { createInertiaApp } from '@inertiajs/svelte'

const appName = import.meta.env.VITE_APP_NAME || 'Coresmith'

void startSentry()

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Install':
            case name === 'auth/SetupRequired':
            case name === 'settings/Removing':
                return null
            case name.startsWith('auth/'):
                return AuthLayout
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout]
            case name.startsWith('account/'):
                return [AppLayout, AccountLayout]
            default:
                return AppLayout
        }
    },
    progress: {
        color: 'oklch(0.74 0.16 45)'
    }
})

initializeTheme()

initializeFlashToast()
