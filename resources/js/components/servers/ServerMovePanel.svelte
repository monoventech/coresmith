<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import { Button } from '@/components/ui/button'
    import type { ServerMove } from '@/lib/moves'
    import { cn } from '@/lib/utils'

    let { move, server }: { move: ServerMove; server: string } = $props()

    let working = $state(false)

    const failed = $derived(!move.away && move.state === 'failed')
    const title = $derived(move.away ? 'Moving to self-hosted Coresmith' : failed ? 'The move stopped' : 'Moving here')
    const body = $derived(
        move.away
            ? 'Coresmith Cloud has stopped managing this server while its team moves to self-hosted Coresmith. It keeps running.'
            : failed
              ? (move.error ?? 'Taking this server over stopped.')
              : 'Coresmith is taking this server over from Coresmith Cloud, without setting it up again. Its apps keep serving the whole time.'
    )

    function retry() {
        router.post(
            route('servers.takeover.store', server),
            {},
            { preserveScroll: true, onStart: () => (working = true), onFinish: () => (working = false) }
        )
    }
</script>

<div
    class={cn('mb-4 rounded-xl border px-5.5 py-4', failed ? 'tint-destructive' : 'border-border bg-card')}
    data-testid="server-move"
>
    <h3 class="type-h3 m-0 mb-1">{title}</h3>
    <p class="m-0 text-[13px]">{body}</p>
    {#if move.can_retry}
        <div class="mt-3">
            <Button variant="outline" size="sm" disabled={working} onclick={retry}>Try again</Button>
        </div>
    {/if}
</div>
