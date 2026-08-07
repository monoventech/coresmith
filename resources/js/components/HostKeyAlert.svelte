<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { router } from '@inertiajs/svelte'
    import * as AlertDialog from '@/components/ui/alert-dialog'
    import { Button } from '@/components/ui/button'

    let {
        serverId,
        expected,
        observed,
        changedAt,
        canManage
    }: {
        serverId: string
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
    class="mb-6 rounded-xl border-2 border-destructive/50 bg-destructive/5 p-5"
    role="alert"
    data-testid="host-key-alert"
>
    <h2 class="text-sm font-semibold text-destructive-strong">Server host key has changed</h2>
    <p class="mt-2 text-sm leading-relaxed">
        This could indicate a man-in-the-middle attack or a server reinstall. Every operation on this server is blocked
        until you decide.
    </p>

    <dl class="mt-4 space-y-2 text-xs">
        <div>
            <dt class="text-muted-foreground">Trusted until now</dt>
            <dd class="font-mono break-all">{expected ?? 'None recorded'}</dd>
        </div>
        <div>
            <dt class="text-muted-foreground">Presented by the server</dt>
            <dd class="font-mono break-all" data-testid="observed-fingerprint">{observed}</dd>
        </div>
        {#if changedAt}
            <div>
                <dt class="text-muted-foreground">First seen</dt>
                <dd>{new Date(changedAt).toLocaleString()}</dd>
            </div>
        {/if}
    </dl>

    <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
        Only accept this if you reinstalled the server or rotated its keys yourself. Check the fingerprint against your
        provider's console before continuing.
    </p>

    {#if canManage}
        <div class="mt-4 flex flex-wrap gap-2">
            <Button variant="destructive" size="sm" disabled={working} onclick={() => (confirming = true)}>
                Accept new key
            </Button>
            <Button variant="outline" size="sm" disabled={working} onclick={reject}>Dismiss for now</Button>
        </div>
    {/if}
</section>

<AlertDialog.Root bind:open={confirming}>
    <AlertDialog.Content>
        <AlertDialog.Header>
            <AlertDialog.Title>Trust this new host key?</AlertDialog.Title>
            <AlertDialog.Description>
                Coresmith will trust this key for every future connection to this server. If you did not reinstall it or
                rotate its keys, someone may be intercepting the connection.
            </AlertDialog.Description>
        </AlertDialog.Header>

        <p class="rounded-md bg-muted p-3 font-mono text-xs break-all">{observed}</p>

        <AlertDialog.Footer>
            <AlertDialog.Cancel disabled={working}>Cancel</AlertDialog.Cancel>
            <AlertDialog.Action disabled={working} onclick={accept}>Yes, trust this key</AlertDialog.Action>
        </AlertDialog.Footer>
    </AlertDialog.Content>
</AlertDialog.Root>
