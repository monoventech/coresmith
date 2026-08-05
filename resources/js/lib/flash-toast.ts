import { router } from '@inertiajs/svelte'
import { toast } from 'svelte-sonner'

interface FlashProps {
    success?: string | null
    error?: string | null
}

function announce(flash: FlashProps | undefined): void {
    if (!flash) {
        return
    }

    if (flash.success) {
        toast.success(flash.success)
    }

    if (flash.error) {
        toast.error(flash.error)
    }
}

export function initializeFlashToast(): void {
    router.on('success', (event) => {
        announce((event.detail.page.props as { flash?: FlashProps }).flash)
    })

    router.on('navigate', (event) => {
        announce((event.detail.page.props as { flash?: FlashProps }).flash)
    })
}
