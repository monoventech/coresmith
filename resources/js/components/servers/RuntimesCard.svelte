<script module lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    export type RuntimeVersion = {
        runtime: string
        version: string
        label: string
        status: { label: string; tone: 'success' | 'warning' | 'destructive' | 'default' }
        apps: { name: string; href: string }[]
        hidden: number
        kept: string[]
        remove_href: string | null
    }

    export type Runtimes = {
        rows: RuntimeVersion[]
        update: { label: string; state: 'installing' | 'failed'; reason: string | null } | null
    }
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'

    let { server, runtimes }: { server: string; runtimes: Runtimes } = $props()

    let confirming = $state<RuntimeVersion | null>(null)
    let sending = $state(false)

    function unseen(count: number): string {
        return count === 1 ? "an app you can't see" : `${count} apps you can't see`
    }

    function list(names: string[]): string {
        return names.length <= 1 ? (names[0] ?? '') : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`
    }

    function remove() {
        const row = confirming

        if (!row?.remove_href) {
            return
        }

        router.delete(row.remove_href, {
            preserveScroll: true,
            onStart: () => (sending = true),
            onFinish: () => ((sending = false), (confirming = null))
        })
    }
</script>

<section
    class="mt-6 overflow-hidden rounded-xl border border-border bg-card"
    aria-labelledby="runtimes-heading"
    data-testid="runtimes-card"
>
    <div class="border-b border-border px-5.5 py-4 max-sm:px-4">
        <h2 id="runtimes-heading" class="type-h3 m-0">Runtimes on {server}</h2>
        <p class="m-0 mt-1 text-[12.5px] text-muted-foreground">
            Each app picks and updates its version on its own page. A version stays while an app runs on it or keeps a
            release built on it.
        </p>
    </div>
    {#if runtimes.update}
        <p
            class="m-0 border-b border-border px-5.5 py-3 text-[13px] max-sm:px-4 {runtimes.update.state === 'failed'
                ? 'text-destructive-strong'
                : 'text-muted-foreground'}"
            role="status"
        >
            {runtimes.update.state === 'failed'
                ? `${runtimes.update.label} couldn't be installed${runtimes.update.reason ? `: ${runtimes.update.reason}` : '.'}`
                : `Installing ${runtimes.update.label}.`}
        </p>
    {/if}
    {#each runtimes.rows as row (`${row.runtime}@${row.version}`)}
        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border px-5.5 py-3.5 last:border-b-0 max-sm:px-4"
            data-testid="runtime-version"
        >
            <span class="min-w-32 font-mono text-[13.5px] font-semibold">{row.label}</span>
            {#if row.status.label}
                <Badge variant={row.status.tone} dot>{row.status.label}</Badge>
            {/if}
            <span class="min-w-0 flex-1 basis-64 text-[13px] text-muted-foreground">
                {#if row.apps.length > 0}
                    Used by {#each row.apps as app, index (app.href)}{index === 0
                            ? ''
                            : index === row.apps.length - 1 && row.hidden === 0
                              ? ' and '
                              : ', '}<Link
                            href={app.href}
                            class="focus-ring rounded-sm font-mono text-foreground underline-offset-4 hover:underline"
                            >{app.name}</Link
                        >{/each}{row.hidden > 0 ? ` and ${unseen(row.hidden)}` : ''}
                {:else if row.hidden > 0}
                    Used by {unseen(row.hidden)}
                {:else if row.kept.length > 0}
                    No app runs on it, but {row.kept.length === 1 ? 'a kept release of' : 'kept releases of'}
                    <span class="font-mono text-foreground">{list(row.kept)}</span>
                    {row.kept.length === 1 ? 'was' : 'were'} built on it, so it stays until
                    {row.kept.length === 1 ? 'that release is' : 'those releases are'} gone.
                {:else}
                    No app uses it.
                {/if}
            </span>
            {#if row.remove_href}
                <Button variant="outline" size="sm" class="max-sm:min-h-11" onclick={() => (confirming = row)}
                    >Remove<span class="sr-only"> {row.label}</span></Button
                >
            {/if}
        </div>
    {/each}
</section>

<Dialog.Root open={confirming !== null} onOpenChange={(open) => !open && !sending && (confirming = null)}>
    <Dialog.Content>
        {#if confirming}
            <Dialog.Header>
                <Dialog.Title>Remove {confirming.label} from {server}?</Dialog.Title>
                <Dialog.Description>
                    No app runs on it and no kept release needs it, so Coresmith deletes its files from {server}. If an
                    app asks for it again, the next deploy installs it again.
                </Dialog.Description>
            </Dialog.Header>
            <Dialog.Footer>
                <Button variant="ghost" type="button" disabled={sending} onclick={() => (confirming = null)}
                    >Cancel</Button
                >
                <Button variant="destructive" type="button" disabled={sending} onclick={remove}>Remove</Button>
            </Dialog.Footer>
        {/if}
    </Dialog.Content>
</Dialog.Root>
