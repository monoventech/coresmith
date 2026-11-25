<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { router } from '@inertiajs/svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { RuntimeRow } from '@/lib/app-page'

    let {
        runtimes,
        app,
        server,
        onchange = null
    }: {
        runtimes: RuntimeRow[]
        app: string
        server: string
        onchange?: (() => void) | null
    } = $props()

    let confirming = $state<RuntimeRow | null>(null)
    let sending = $state(false)

    const installing = $derived(runtimes.some((row) => row.update?.state === 'installing'))

    $effect(() => {
        if (!installing) {
            return
        }

        const timer = setInterval(() => router.reload({ only: ['runtimes'] }), 5000)

        return () => clearInterval(timer)
    })

    function update() {
        const row = confirming

        if (!row?.update_href) {
            return
        }

        router.post(
            row.update_href,
            { runtime: row.runtime },
            {
                preserveScroll: true,
                onStart: () => (sending = true),
                onFinish: () => ((sending = false), (confirming = null))
            }
        )
    }
</script>

<div class="min-w-0 overflow-hidden rounded-xl border border-border bg-card" data-testid="runtime-card">
    <div class="px-5.5 pt-5 pb-3.5 max-sm:px-4 max-sm:pt-4">
        <h2 id="runtime-title" class="type-h3 m-0 max-sm:text-[16px]">Runtime</h2>
        <p class="m-0 mt-1 text-[12.5px] text-muted-foreground">
            Versions install side by side on <span class="font-mono whitespace-nowrap">{server}</span>, so
            <span class="font-mono whitespace-nowrap">{app}</span> keeps its own.
        </p>
    </div>

    {#each runtimes as row, index (row.runtime)}
        <div class="flex flex-col gap-3 border-t border-border px-5.5 py-4 max-sm:px-4" data-testid="runtime-row">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span class="font-mono text-[15px] font-semibold">{row.name} {row.version}</span>
                {#if row.status.label}
                    <Badge variant={row.status.tone} dot>{row.status.label}</Badge>
                {/if}
                <span class="text-[12.5px] text-muted-foreground">{row.source}</span>
                {#if index === 0 && onchange}
                    <Button variant="ghost" size="sm" class="ml-auto max-sm:-ml-3 max-sm:min-h-11" onclick={onchange}
                        >Change version<span class="sr-only"> of {row.name}</span></Button
                    >
                {/if}
            </div>

            {#if row.update}
                <div
                    class="rounded-lg border px-3.5 py-3 text-[13px] {row.update.state === 'failed'
                        ? 'tint-destructive'
                        : 'border-border bg-muted/40'}"
                    role="status"
                >
                    {#if row.update.state === 'installing'}
                        Installing {row.update.label}. <span class="font-mono whitespace-nowrap">{app}</span> deploys on it
                        once it's in.
                    {:else}
                        {row.update.label} couldn't be installed{row.update.reason ? `: ${row.update.reason}` : '.'}
                    {/if}
                </div>
            {:else if row.newest}
                <div
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-3.5 py-3"
                    data-testid="runtime-newer"
                >
                    <div class="min-w-0 flex-1 basis-72">
                        <p class="m-0 text-[13.5px] font-semibold">
                            {row.name}
                            {row.newest} is the newest in this line
                        </p>
                        <p class="m-0 mt-0.5 text-[12.5px] text-muted-foreground">
                            {#if row.pinned}
                                Its <span class="font-mono">{row.pinned}</span> asks for exactly {row.version}. Change
                                it there to move up.
                            {:else}
                                Updating installs {row.newest} beside {row.version} and redeploys
                                <span class="font-mono whitespace-nowrap">{app}</span>. Other apps on {row.version} move up
                                the next time they deploy.
                            {/if}
                        </p>
                    </div>
                    {#if row.update_href}
                        <Button
                            variant="secondary"
                            size="sm"
                            class="max-sm:min-h-11 max-sm:w-full"
                            onclick={() => (confirming = row)}>Update to {row.newest}</Button
                        >
                    {/if}
                </div>
            {/if}
        </div>
    {/each}
</div>

<Dialog.Root open={confirming !== null} onOpenChange={(open) => !open && !sending && (confirming = null)}>
    <Dialog.Content>
        {#if confirming}
            <Dialog.Header>
                <Dialog.Title>Update {confirming.name} to {confirming.newest}?</Dialog.Title>
                <Dialog.Description>
                    Coresmith installs {confirming.name}
                    {confirming.newest} on {server}, beside {confirming.version}, then deploys {app} on it. If the deploy
                    fails, the release that's live now keeps serving.
                </Dialog.Description>
            </Dialog.Header>
            <Dialog.Footer>
                <Button variant="ghost" type="button" disabled={sending} onclick={() => (confirming = null)}
                    >Cancel</Button
                >
                <Button type="button" disabled={sending} onclick={update}>Update and deploy</Button>
            </Dialog.Footer>
        {/if}
    </Dialog.Content>
</Dialog.Root>
