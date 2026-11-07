<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { GitHubRegisteredApp } from '@/lib/github'
    import { router } from '@inertiajs/svelte'
    import { GitBranch } from '@lucide/svelte'

    let { app, canManage }: { app: GitHubRegisteredApp; canManage: boolean } = $props()

    let removing = $state(false)
    let working = $state(false)

    function remove() {
        if (!app.remove_href) {
            return
        }

        working = true
        router.delete(app.remove_href, {
            preserveScroll: true,
            onFinish: () => ((working = false), (removing = false))
        })
    }
</script>

<div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="github-app-card">
    <div class="mb-1 flex flex-wrap items-center gap-2.5">
        <GitBranch class="size-4.5 text-muted-foreground" />
        <h3 class="type-h3 m-0">GitHub App</h3>
        <Badge variant="outline">Not installed</Badge>
    </div>
    <p class="mb-1 text-[12.5px] text-muted-foreground">
        {app.name} is registered on GitHub under <span class="font-mono text-[12px]">{app.owner}</span>. Install it on
        the account that holds your repositories.
    </p>
    <p class="m-0 text-[12.5px] text-muted-foreground">
        While the app is private, only <span class="font-mono text-[12px]">{app.owner}</span> can install it.
    </p>
    {#if canManage}
        <div class="mt-3 flex flex-wrap gap-2">
            <Button size="sm" href={app.install_href} target="_self">Install on GitHub</Button>
            <Button variant="outline" size="sm" href={app.link_href} target="_self"
                >Link an existing installation</Button
            >
            {#if app.remove_href}
                <Button variant="ghost" size="sm" class="text-destructive-strong" onclick={() => (removing = true)}
                    >Remove</Button
                >
            {/if}
        </div>
    {/if}
</div>

<Dialog.Root bind:open={removing}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Remove {app.name} from Coresmith?</Dialog.Title>
            <Dialog.Description>
                Coresmith forgets the app's key and secrets. The app stays on GitHub until you delete it in its settings
                there.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (removing = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={remove}>Remove the app</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
