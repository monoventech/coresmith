import type { FlashToast } from '@/types/ui'
import { router } from '@inertiajs/svelte'
import { toast } from 'svelte-sonner'

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash
        const data = flash?.toast as FlashToast | undefined

        if (!data) {
            return
        }

        toast[data.type](data.message)
    })
}
