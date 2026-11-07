<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import type { ServerHeader } from '@/lib/servers'
    import { router } from '@inertiajs/svelte'
    import { OctagonAlert } from '@lucide/svelte'

    let { server, ondetach }: { server: ServerHeader; ondetach: () => void } = $props()

    let working = $state(false)

    const cloud = $derived(server.cloud)
    const task = $derived(cloud?.task?.type === 'destroy' ? cloud.task : null)
    const resize = $derived(cloud?.task?.type === 'resize' ? cloud.task : null)

    function dismiss() {
        working = true
        router.delete(route('servers.cloud.dismiss', server.id), {
            preserveScroll: true,
            onFinish: () => (working = false)
        })
    }

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
        class="tint-primary mt-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:items-start max-sm:px-3.5"
        data-testid="cloud-task-banner"
    >
        <StateDot tone="accent" state="destroying" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]">
            Destroying {server.name} at {cloud.provider}. It leaves the fleet once {cloud.provider} confirms.
        </span>
    </div>
{:else if cloud && task?.state === 'failed'}
    <div
        class="tint-destructive mt-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:items-start max-sm:px-3.5"
        data-testid="cloud-task-banner"
    >
        <OctagonAlert class="size-4.5 shrink-0 text-destructive max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]"
            >{task.error}</span
        >
        {#if cloud.can.retry}
            <Button variant="outline" size="sm" class="max-sm:ml-7" disabled={working} onclick={retry}>Try again</Button
            >
        {/if}
        {#if cloud.can.detach}
            <Button variant="ghost" size="sm" onclick={ondetach}>Detach</Button>
        {/if}
    </div>
{:else if cloud && resize?.state === 'running'}
    <div
        class="tint-primary mt-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:items-start max-sm:px-3.5"
        data-testid="cloud-task-banner"
    >
        <StateDot tone="accent" state="resizing" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]">
            {`Resizing ${server.name} to ${resize.to}, ${resize.step_label}. Apps are offline until it starts again.`}
        </span>
    </div>
{:else if cloud && resize?.state === 'failed'}
    <div
        class="tint-destructive mt-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:items-start max-sm:px-3.5"
        data-testid="cloud-task-banner"
    >
        <OctagonAlert class="size-4.5 shrink-0 text-destructive max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]"
            >{resize.error}</span
        >
        {#if cloud.can.dismiss}
            <Button variant="ghost" size="sm" class="max-sm:ml-7" disabled={working} onclick={dismiss}>Dismiss</Button>
        {/if}
    </div>
{/if}
