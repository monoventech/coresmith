<script lang="ts">
    import CloudSteps from '@/components/cloud/CloudSteps.svelte'
    import DestroyServerDialog from '@/components/cloud/DestroyServerDialog.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { CloudServer } from '@/lib/cloud'
    import type { ServerHeader } from '@/lib/servers'
    import { router } from '@inertiajs/svelte'

    let {
        server,
        cloud,
        onretry,
        onrestart
    }: { server: ServerHeader; cloud: CloudServer; onretry: () => void; onrestart: () => void } = $props()

    let removing = $state(false)
    let destroying = $state(false)
    let detaching = $state(false)

    function detach() {
        working = true
        router.delete(route('servers.destroy', server.id), { onFinish: () => ((working = false), (detaching = false)) })
    }
    let working = $state(false)

    const task = $derived(
        cloud.task?.type === 'create' && server.provisioning?.status === 'creating' ? cloud.task : null
    )
    const done = $derived((cloud.task?.steps ?? []).map((step) => ({ ...step, state: 'done' as const })))
    const provisioningFailed = $derived(server.provisioning?.status === 'failed')

    function retry() {
        working = true
        router.post(
            route('servers.cloud.retry', server.id),
            {},
            { preserveScroll: true, onFinish: () => (working = false) }
        )
    }

    function remove() {
        working = true
        router.delete(route('servers.destroy', server.id), { onFinish: () => ((working = false), (removing = false)) })
    }
</script>

<div class="rounded-xl border border-border bg-card p-6" data-testid="creation-card">
    {#if task?.state === 'running'}
        <div class="mb-1.5 flex flex-wrap items-center gap-3">
            <h3 class="type-h3 m-0">Creating {server.name}</h3>
            <Badge variant="accent" dot pulse>Step {task.position} of {task.total}</Badge>
        </div>
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            At {cloud.provider}, {cloud.size} in {cloud.region}, {cloud.image}. {cloud.provider} bills it from now{cloud.monthly
                ? `, about ${cloud.monthly} a month`
                : ''}. Close this page, it keeps going.
        </p>
        <CloudSteps steps={task.steps} />
    {:else if task?.state === 'failed'}
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <h3 class="type-h3 m-0">Creating {server.name} failed</h3>
            <Badge variant="destructive" dot>Failed at step {task.position}</Badge>
        </div>
        <CloudSteps steps={task.steps} />
        <div
            class="tint-destructive mt-3 rounded-lg border px-3.5 py-3 text-[12.5px] leading-normal"
            data-testid="creation-error"
        >
            {task.error}
        </div>
        {#if cloud.can.retry || cloud.can.remove || cloud.can.destroy}
            <div class="mt-4 flex flex-wrap gap-2">
                {#if cloud.can.retry}
                    <Button size="sm" disabled={working} onclick={retry}>Try again</Button>
                {/if}
                {#if cloud.can.remove}
                    <Button variant="outline" size="sm" onclick={() => (removing = true)}>Remove</Button>
                {:else if cloud.can.destroy}
                    <Button variant="outline" size="sm" onclick={() => (destroying = true)}>Destroy it</Button>
                {/if}
            </div>
        {/if}
    {:else}
        <h3 class="type-h3 m-0 mb-1">Created at {cloud.provider}</h3>
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            {[
                cloud.ip,
                cloud.size && cloud.region ? `${cloud.size} in ${cloud.region}` : null,
                cloud.monthly ? `${cloud.monthly} a month before tax` : null
            ]
                .filter(Boolean)
                .join(' · ')}
        </p>
        <CloudSteps steps={done} />
        {#if provisioningFailed && server.can.update}
            <div class="mt-4 flex flex-wrap gap-2">
                <Button size="sm" onclick={onretry}>{`Retry from phase ${server.provisioning?.failed_phase}`}</Button>
                <Button variant="outline" size="sm" onclick={onrestart}>Start over</Button>
            </div>
        {/if}
    {/if}
</div>

<DestroyServerDialog
    server={{ id: server.id, name: server.name }}
    provider={cloud.provider}
    bind:open={destroying}
    ondetach={() => (detaching = true)}
/>

<Dialog.Root bind:open={detaching}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Detach {server.name}?</Dialog.Title>
            <Dialog.Description
                >Coresmith stops managing {server.name}. It keeps running at {cloud.provider}, and {cloud.provider} keeps
                billing for it.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (detaching = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={detach}>Detach server</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={removing}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Remove {server.name}?</Dialog.Title>
            <Dialog.Description
                >Nothing was created at {cloud.provider}, so there is nothing to destroy.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (removing = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={remove}>Remove server</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
