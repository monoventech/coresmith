import { notify, splitMessage } from '@/lib/toast.svelte'
import { router } from '@inertiajs/svelte'

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const { success, error } = event.detail.flash

        if (error) {
            const { title, description } = splitMessage(error)
            notify(title, description, 'destructive')

            return
        }

        if (success) {
            const { title, description } = splitMessage(success)
            notify(title, description, 'success')
        }
    })
}
