<script lang="ts">
    import { dismissToast, toastState } from '@/lib/toast.svelte'
    import { cn } from '@/lib/utils'
    import { X } from '@lucide/svelte'
    import { fly } from 'svelte/transition'

    const toast = toastState()

    const dots: Record<string, string> = {
        success: 'bg-success',
        warning: 'bg-warning',
        destructive: 'bg-destructive'
    }
</script>

<div
    class="pointer-events-none fixed right-5 bottom-5 z-60 w-80 max-w-[calc(100vw-2.5rem)] max-sm:bottom-[calc(env(safe-area-inset-bottom)+4.5rem)]"
    aria-live="polite"
>
    {#if toast.current}
        {#key toast.current.id}
            <div
                role="status"
                data-testid="toast"
                class="panel pointer-events-auto absolute right-0 bottom-0 flex w-full items-start gap-2.5 rounded-xl border border-border bg-popover px-4 py-3 text-popover-foreground shadow-(--shadow-md)"
                transition:fly={{ y: 8, duration: 200 }}
            >
                {#if dots[toast.current.tone]}
                    <span
                        class={cn('mt-1.5 size-1.5 shrink-0 rounded-full', dots[toast.current.tone])}
                        aria-hidden="true"
                    ></span>
                {/if}
                <div class="min-w-0 flex-1">
                    <p class="text-[14px] font-medium">{toast.current.title}</p>
                    {#if toast.current.description}
                        <p class="mt-0.5 text-[13px] leading-normal text-muted-foreground">
                            {toast.current.description}
                        </p>
                    {/if}
                </div>
                <button
                    type="button"
                    class="focus-ring cursor-pointer rounded-sm p-0.5 leading-none text-muted-foreground"
                    aria-label="Dismiss"
                    onclick={dismissToast}
                >
                    <X class="size-3.5" />
                </button>
            </div>
        {/key}
    {/if}
</div>
