<script lang="ts">
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import type { ServerHeader } from '@/lib/servers'
    import { router } from '@inertiajs/svelte'
    import { OctagonAlert } from '@lucide/svelte'

    let { server, ondetach }: { server: ServerHeader; ondetach: () => void } = $props()

    let working = $state(false)

    const cloud = $derived(server.cloud)
    const task = $derived(cloud?.task?.type === 'destroy' ? cloud.task : null)

    function retry() {
        working = true
        router.post(
            route('servers.cloud.retry', server.id),
            {},
            { preserveScroll: true, onFinish: () => (working = false) }
        )
    }
</script>

{#if cloud && task?.state === 'running'}
    <div
        class="tint-primary mt-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3"
        data-testid="cloud-task-banner"
    >
        <StateDot tone="accent" state="destroying" />
        <span class="min-w-0 flex-1 text-[13.5px]">
            Destroying {server.name} at {cloud.provider}. It leaves the fleet once {cloud.provider} confirms.
        </span>
    </div>
{:else if cloud && task?.state === 'failed'}
    <div
        class="tint-destructive mt-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3"
        data-testid="cloud-task-banner"
    >
        <OctagonAlert class="size-4.5 shrink-0 text-destructive" />
        <span class="min-w-0 flex-1 text-[13.5px]">{task.error}</span>
        {#if cloud.can.retry}
            <Button variant="outline" size="sm" disabled={working} onclick={retry}>Try again</Button>
        {/if}
        <Button variant="ghost" size="sm" onclick={ondetach}>Detach</Button>
    </div>
{/if}
