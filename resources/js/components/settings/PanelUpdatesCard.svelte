<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Form, router } from '@inertiajs/svelte'
    import { OctagonAlert } from '@lucide/svelte'
    import { untrack } from 'svelte'
    import StateDot from '@/components/StateDot.svelte'
    import ToggleRow from '@/components/ToggleRow.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { createUpdatePoller, hasFailed, isActive } from '@/lib/panelUpdates.svelte'
    import type { PanelUpdates } from '@/lib/panelUpdates.svelte'

    let { updates, canManage }: { updates: PanelUpdates; canManage: boolean } = $props()

    const poller = untrack(() => createUpdatePoller(updates.status_href, updates.run))

    let target = $state<string | null>(null)
    let logOpen = $state(false)
    let logLines = $state<string[]>([])
    let checking = $state(false)
    let autoBusy = $state(false)

    const run = $derived(poller.run)
    const controls = $derived(updates.mode === 'self-hosted' && canManage)

    const progress = $derived.by(() => {
        if (run === null) {
            return ''
        }

        if (poller.restarting && run.to) {
            return `Restarting on v${run.to}. This page reconnects by itself.`
        }

        if (run.state === 'requested') {
            return run.stalled
                ? "The update service hasn't started. Check coresmith-update.path is enabled, or run the update from the shell."
                : 'Waiting for the update service to start.'
        }

        if (run.state === 'starting') {
            return 'Starting the update.'
        }

        return `${run.label} · step ${run.number} of ${run.total}`
    })

    const lead = $derived.by(() => {
        if (run === null) {
            return ''
        }

        switch (run.state) {
            case 'rolled_back':
                return `The update to v${run.to} failed while ${run.failed_label ?? 'updating'}, so Coresmith rolled back to v${run.from}.`
            case 'aborted':
                return `The update to v${run.to} stopped before changing anything.`
            case 'failed':
                return `The update to v${run.to} failed and the rollback didn't finish.`
            default:
                return `The update to v${run.to} stopped partway through ${lower(run.label ?? 'the update')}.`
        }
    })

    $effect(() => {
        poller.sync(updates.run)
    })

    $effect(() => () => poller.stop())

    function lower(text: string): string {
        return text.charAt(0).toLowerCase() + text.slice(1)
    }

    function confirm(version: string | null | undefined) {
        target = version ?? null
    }

    function check() {
        checking = true
        router.post(route('settings.updates.check'), {}, { preserveScroll: true, onFinish: () => (checking = false) })
    }

    function cancel() {
        router.delete(route('settings.updates.cancel'), { preserveScroll: true })
    }

    function recover() {
        router.post(route('settings.updates.recover'), {}, { preserveScroll: true })
    }

    function auto(on: boolean) {
        autoBusy = true
        router.put(route('settings.updates.auto'), { on }, { preserveScroll: true, onFinish: () => (autoBusy = false) })
    }

    async function openLog() {
        logLines = run?.log ?? []
        logOpen = true

        if (!run?.log_href) {
            return
        }

        try {
            const response = await fetch(run.log_href, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })

            if (response.ok) {
                logLines = ((await response.json()) as { lines: string[] }).lines
            }
        } catch {
            return
        }
    }
</script>

<div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="panel-updates-card">
    <div class="mb-1 flex items-center gap-2.5">
        <h3 class="type-h3 m-0">Panel updates</h3>
        {#if updates.badge}
            <Badge
                variant={updates.badge.tone}
                dot={updates.badge.tone === 'accent'}
                pulse={updates.badge.tone === 'accent'}>{updates.badge.label}</Badge
            >
        {/if}
    </div>
    <p class="mb-3.5 text-[12.5px] text-muted-foreground" data-testid="panel-updates-line">{updates.line}</p>

    {#each updates.notices as notice, index (index)}
        <p
            class={notice.tone === 'warning'
                ? 'mb-2 text-[12.5px] text-warning-strong'
                : 'mb-2 text-[12.5px] text-muted-foreground'}
        >
            {notice.text}
        </p>
    {/each}

    {#if controls}
        {#if run && isActive(run)}
            <div class="flex min-h-13 items-start gap-3 border-t border-border py-2" data-testid="update-progress">
                <StateDot tone="accent" state="running" class="mt-2" />
                <div class="min-w-0 flex-1">
                    <div class="text-[14px] font-medium">
                        Updating to <span class="font-mono text-[13px]">v{run.to}</span>
                    </div>
                    <div class="mt-0.5 text-[12.5px] text-muted-foreground" role="status">{progress}</div>
                </div>
                {#if run.state === 'requested'}
                    <Button variant="ghost" size="sm" onclick={cancel}>Cancel request</Button>
                {/if}
            </div>
            {#if run.log.length > 0}
                <div
                    class="terminal mb-1 h-30 overflow-y-auto px-3.5 py-2.5 font-mono text-[12px] leading-[1.7]"
                    role="log"
                    aria-label="Update log"
                    data-testid="update-terminal"
                >
                    {#each run.log as line, index (index)}
                        <div class="break-all whitespace-pre-wrap">{line}</div>
                    {/each}
                </div>
            {/if}
        {:else if run && hasFailed(run)}
            <section
                class="tint-destructive mb-2 flex items-start gap-3 rounded-xl border px-4.5 py-3.5"
                role="alert"
                data-testid="update-failed"
            >
                <OctagonAlert class="mt-0.5 size-4.5 shrink-0 text-destructive" />
                <div class="min-w-0 flex-1 text-[13.5px] leading-[1.55]">
                    <p class="font-semibold">{lead}</p>
                    {#if run.message}
                        <p class="mt-1 text-[12.5px] text-muted-foreground">{run.message}</p>
                    {/if}
                    <div class="mt-2 -ml-3 flex flex-wrap gap-2">
                        {#if run.log_href}
                            <Button variant="ghost" size="sm" onclick={openLog}>View log</Button>
                        {/if}
                        {#if run.state === 'aborted' || run.state === 'rolled_back'}
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={!updates.managed || !(updates.available?.version ?? run.to)}
                                onclick={() => confirm(updates.available?.version ?? run.to)}>Try again</Button
                            >
                        {:else if run.state === 'interrupted'}
                            <Button variant="ghost" size="sm" onclick={recover}>Recover</Button>
                        {/if}
                    </div>
                </div>
            </section>
        {:else if updates.available}
            <div class="flex min-h-13 items-center gap-3 border-t border-border py-2" data-testid="update-available">
                <div class="min-w-0 flex-1">
                    <div class="text-[14px] font-medium">
                        <span class="font-mono text-[13px]">v{updates.available.version}</span> is available
                    </div>
                    {#if updates.available.summary}
                        <p class="mt-0.5 line-clamp-4 text-[12.5px] whitespace-pre-line text-muted-foreground">
                            {updates.available.summary}
                        </p>
                    {/if}
                    {#if updates.available.notes_url}
                        <a
                            href={updates.available.notes_url}
                            target="_blank"
                            rel="noreferrer"
                            class="focus-ring mt-1 inline-block rounded-sm text-[12.5px] font-medium text-primary-strong no-underline underline-offset-4 hover:underline"
                            >Release notes</a
                        >
                    {/if}
                </div>
                {#if updates.managed}
                    <Button onclick={() => confirm(updates.available?.version)}>Update now</Button>
                {/if}
            </div>
        {/if}

        <ToggleRow
            label="Install patch updates automatically"
            description="At 4:00 AM, with a backup first and automatic recovery if the health check fails. Minor and major versions always ask."
            checked={updates.auto}
            busy={autoBusy}
            onchange={auto}
            testid="auto-patch"
        />

        {#if updates.check.enabled}
            <div class="mt-auto self-start pt-3">
                <Button variant="outline" size="sm" disabled={checking} onclick={check}>Check for updates</Button>
            </div>
        {/if}
    {/if}
</div>

{#if controls}
    <Dialog.Root open={target !== null} onOpenChange={(open) => !open && (target = null)}>
        <Dialog.Content>
            <Form
                method="post"
                action={route('settings.updates.store')}
                options={{ preserveScroll: true }}
                onSuccess={() => (target = null)}
            >
                {#snippet children({ processing })}
                    <Dialog.Header>
                        <Dialog.Title>Update to v{target}?</Dialog.Title>
                        <Dialog.Description>
                            Coresmith builds the new release beside this one, takes a backup, then goes into maintenance
                            for about a minute while it migrates and restarts. If any step fails it rolls back on its
                            own.
                        </Dialog.Description>
                    </Dialog.Header>
                    <input type="hidden" name="to" value={target ?? ''} />
                    <Dialog.Footer>
                        <Button variant="ghost" type="button" onclick={() => (target = null)}>Not now</Button>
                        <Button type="submit" disabled={processing}>Update</Button>
                    </Dialog.Footer>
                {/snippet}
            </Form>
        </Dialog.Content>
    </Dialog.Root>

    <Dialog.Root bind:open={logOpen}>
        <Dialog.Content>
            <Dialog.Header>
                <Dialog.Title>Update log</Dialog.Title>
                <Dialog.Description>Every line the update wrote, with secrets redacted.</Dialog.Description>
            </Dialog.Header>
            <div
                class="terminal mt-4 max-h-96 overflow-auto px-3.5 py-2.5 font-mono text-[12px] leading-[1.7]"
                role="log"
                aria-label="Update log"
                data-testid="update-log"
            >
                {#each logLines as line, index (index)}
                    <div class="break-all whitespace-pre-wrap">{line}</div>
                {/each}
            </div>
            <Dialog.Footer>
                <Button variant="ghost" onclick={() => (logOpen = false)}>Close</Button>
            </Dialog.Footer>
        </Dialog.Content>
    </Dialog.Root>
{/if}
