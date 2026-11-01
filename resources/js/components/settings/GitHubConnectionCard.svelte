<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { GitHubConnect, GitHubConnection } from '@/lib/github'
    import { router } from '@inertiajs/svelte'
    import { GitBranch, Timer, TriangleAlert } from '@lucide/svelte'

    let {
        connection,
        connect = null,
        canManage
    }: { connection: GitHubConnection; connect?: GitHubConnect | null; canManage: boolean } = $props()

    let disconnecting = $state(false)
    let working = $state(false)

    const repositories = $derived.by(() => {
        if (connection.repository_selection === 'all') {
            return 'all repositories'
        }

        if (connection.repositories === null) {
            return null
        }

        return connection.repositories === 1 ? '1 repository' : `${connection.repositories} repositories`
    })

    const token = $derived(expiry(connection.token_expires_at))

    function expiry(iso: string | null): string {
        const minutes = iso === null ? 0 : Math.floor((new Date(iso).getTime() - Date.now()) / 60_000)

        if (minutes <= 0) {
            return 'No token is held right now. Coresmith mints a one-hour token when it next needs one.'
        }

        if (minutes < 90) {
            return `Installation token expires in ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}, rotated automatically.`
        }

        return `Installation token expires in ${Math.round(minutes / 60)} hours, rotated automatically.`
    }

    function disconnect() {
        working = true
        router.delete(connection.disconnect_href, {
            preserveScroll: true,
            onFinish: () => ((working = false), (disconnecting = false))
        })
    }
</script>

<div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="provider-card">
    <div class="mb-1 flex flex-wrap items-center gap-2.5">
        <GitBranch class="size-4.5 text-muted-foreground" />
        <h3 class="type-h3 m-0">GitHub App</h3>
        {#if connection.suspended}
            <Badge variant="warning">Suspended</Badge>
        {:else}
            <Badge variant="success">Installed</Badge>
        {/if}
    </div>
    <p class="mb-3 text-[12.5px] text-muted-foreground">
        Installed on <span class="font-mono text-[12px]">{connection.account}</span
        >{#if repositories}{` · ${repositories}`}{/if}
        · {connection.permissions}
    </p>
    {#if connection.suspended}
        <div class="tint-warning flex items-center gap-3 rounded-md border px-3.5 py-2.5 text-[12.5px]">
            <TriangleAlert class="size-4 shrink-0 text-warning" />
            GitHub has suspended this installation. Unsuspend it on GitHub to deploy again.
        </div>
    {:else}
        <div class="flex items-center gap-3 rounded-md bg-muted px-3.5 py-2.5 text-[12.5px]">
            <Timer class="size-4 shrink-0 text-muted-foreground" />
            {token}
        </div>
    {/if}
    {#if canManage}
        <div class="mt-3 flex flex-wrap gap-2">
            {#if connection.manage_url}
                <Button
                    variant="outline"
                    size="sm"
                    href={connection.manage_url}
                    target="_blank"
                    rel="noopener noreferrer">Manage repositories</Button
                >
            {/if}
            <Button variant="ghost" size="sm" class="text-destructive-strong" onclick={() => (disconnecting = true)}
                >Disconnect</Button
            >
            {#if connect}
                <Button variant="ghost" size="sm" href={connect.install_href} target="_self"
                    >Connect another account</Button
                >
            {/if}
        </div>
    {/if}
</div>

<Dialog.Root bind:open={disconnecting}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Disconnect GitHub on {connection.account}?</Dialog.Title>
            <Dialog.Description>
                Coresmith stops using this installation and forgets its token. GitHub keeps the app installed until you
                uninstall it there. Apps that clone through it can still deploy public repositories.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (disconnecting = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={disconnect}>Disconnect</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
