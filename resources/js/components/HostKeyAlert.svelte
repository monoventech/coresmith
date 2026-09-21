<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { router } from '@inertiajs/svelte'
    import { OctagonAlert } from '@lucide/svelte'

    let {
        serverId,
        serverName,
        expected,
        observed,
        changedAt,
        canManage
    }: {
        serverId: string
        serverName: string
        expected: string | null
        observed: string
        changedAt: string | null
        canManage: boolean
    } = $props()

    let confirming = $state(false)
    let working = $state(false)

    function accept() {
        working = true
        router.post(
            route('servers.host-key.accept', serverId),
            { fingerprint: observed },
            { preserveScroll: true, onFinish: () => ((working = false), (confirming = false)) }
        )
    }

    function reject() {
        working = true
        router.post(
            route('servers.host-key.reject', serverId),
            {},
            { preserveScroll: true, onFinish: () => (working = false) }
        )
    }
</script>

<section
    class="tint-destructive mt-4 flex items-start gap-3 rounded-xl border px-4.5 py-3.5"
    role="alert"
    data-testid="host-key-alert"
>
    <OctagonAlert class="mt-0.5 size-4.5 shrink-0 text-destructive" />
    <div class="min-w-0 flex-1 text-[13.5px] leading-[1.55]">
        <p>
            <span class="font-semibold">{serverName} presented a different host key.</span>
            Every operation on this server is paused until you decide. Only trust it if you reinstalled the server or rotated
            its keys yourself.
        </p>
        <dl class="mt-2.5 grid gap-1 text-[12.5px]">
            <div class="flex flex-wrap gap-x-2">
                <dt class="text-muted-foreground">Trusted until now</dt>
                <dd class="font-mono break-all">{expected ?? 'none recorded'}</dd>
            </div>
            <div class="flex flex-wrap gap-x-2">
                <dt class="text-muted-foreground">Presented now</dt>
                <dd class="font-mono break-all" data-testid="observed-fingerprint">{observed}</dd>
            </div>
            {#if changedAt}
                <div class="flex flex-wrap gap-x-2">
                    <dt class="text-muted-foreground">First seen</dt>
                    <dd>{new Date(changedAt).toLocaleString()}</dd>
                </div>
            {/if}
        </dl>
        {#if canManage}
            <div class="mt-3 flex flex-wrap gap-2">
                <Button size="sm" variant="destructive" disabled={working} onclick={() => (confirming = true)}
                    >Trust the new key</Button
                >
                <Button size="sm" variant="outline" disabled={working} onclick={reject}>Dismiss for now</Button>
            </div>
        {/if}
    </div>
</section>

<Dialog.Root bind:open={confirming}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Trust this new host key?</Dialog.Title>
            <Dialog.Description>
                Coresmith trusts this key for every future connection to {serverName}. If you did not reinstall it or
                rotate its keys, someone may be intercepting the connection.
            </Dialog.Description>
        </Dialog.Header>
        <p class="mt-4 rounded-md bg-muted px-3.5 py-2.5 font-mono text-[12px] break-all">{observed}</p>
        <Dialog.Footer>
            <Button variant="ghost" disabled={working} onclick={() => (confirming = false)}>Keep it blocked</Button>
            <Button variant="destructive" disabled={working} onclick={accept}>Trust this key</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
