<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { router } from '@inertiajs/svelte'
    import { TriangleAlert, X } from '@lucide/svelte'
    import { untrack } from 'svelte'
    import CronJobDialog from '@/components/applications/CronJobDialog.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { CronCard, CronJob, CronSend } from '@/lib/cron'
    import { cn } from '@/lib/utils'

    let {
        cron,
        server,
        canUpdate,
        canViewLogs,
        busy,
        send,
        class: className = undefined
    }: {
        cron: CronCard
        server: string
        canUpdate: boolean
        canViewLogs: boolean
        busy: boolean
        send: CronSend
        class?: string
    } = $props()

    let editing = $state<CronJob | null>(null)
    let editor = $state(false)
    let removing = $state<CronJob | null>(null)
    let open = $state(false)
    let output = $state<{ lines: string[]; error: string | null; loading: boolean }>({
        lines: [],
        error: null,
        loading: true
    })
    let panel = $state<HTMLDivElement | null>(null)
    let shown = ''

    $effect(() => {
        if (!open) {
            return
        }

        const href = untrack(() => cron.log_href)
        let closed = false

        async function read() {
            try {
                const response = await fetch(href, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                const body = (await response.json()) as { lines?: string[]; error?: string | null; pending?: boolean }

                if (!closed) {
                    output = {
                        lines: body.lines ?? [],
                        error: body.error ?? (response.ok ? null : unreadable()),
                        loading: Boolean(body.pending)
                    }
                }
            } catch {
                if (!closed) {
                    output = { lines: [], error: unreadable(), loading: false }
                }
            }
        }

        output = { lines: [], error: null, loading: true }
        shown = ''
        void read()

        const timer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                void read()
            }
        }, 10_000)

        return () => {
            closed = true
            clearInterval(timer)
        }
    })

    $effect(() => {
        const text = output.lines.join('\n')

        if (panel && text !== shown) {
            shown = text
            panel.scrollTop = panel.scrollHeight
        }
    })

    function unreadable() {
        return `Couldn't read the scheduler log on ${server}.`
    }

    function add() {
        editing = null
        editor = true
    }

    function edit(job: CronJob) {
        editing = { ...job }
        editor = true
    }

    function remove() {
        const target = removing

        if (!target) {
            return
        }

        send((finish) =>
            router.delete(target.href, { preserveScroll: true, onSuccess: () => (removing = null), onFinish: finish })
        )
    }
</script>

<div
    class={cn('flex flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden', className)}
    data-testid="cron-jobs"
>
    <h3 class="type-h3 m-0 mb-1">Cron jobs</h3>
    <p class="mb-3 text-[12.5px] text-muted-foreground">
        Each job runs as <span class="font-mono text-[12px]">{cron.user}</span>
        {`in the current release, with the app's environment. Saving changes what runs on ${server}, no deployment needed.`}
    </p>

    {#if cron.note}
        <p class="mb-3 text-[12.5px] text-muted-foreground" data-testid="cron-note">
            {cron.note === 'paused'
                ? 'Paused while the app is stopped. The jobs run again when you start it.'
                : 'Nothing is live yet, so these jobs start with the first deploy.'}
        </p>
    {/if}

    {#if cron.state}
        <p
            class="mb-3 flex items-center gap-2 text-[12.5px] text-muted-foreground"
            role="status"
            data-testid="cron-status"
        >
            {#if cron.state === 'applying'}
                <span class="status-dot-working size-2 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                <span>{`Writing the cron file on ${server}…`}</span>
            {:else}
                <TriangleAlert class="size-3.5 shrink-0 text-warning" aria-hidden="true" />
                <span
                    >{`Still waiting to reach ${server}. Coresmith's queue may be stopped, and the next deploy writes the cron file too.`}</span
                >
            {/if}
        </p>
    {/if}

    {#each cron.jobs as job, index (`${index}:${job.id}`)}
        <div class="flex min-h-14 items-center gap-3 border-t border-border py-2" data-testid="cron-job">
            <div class="min-w-0 flex-1">
                <div
                    class={job.title_is_command
                        ? 'truncate font-mono text-[13px] font-medium'
                        : 'truncate text-[14px] font-medium'}
                >
                    {job.title}
                </div>
                <div class="mt-0.5 truncate text-[12.5px] text-muted-foreground">
                    {job.sentence ?? 'Custom schedule'} · <span class="font-mono text-[12px]">{job.schedule}</span>
                    {#if !job.title_is_command}· <span class="font-mono text-[12px]">{job.command_summary}</span>{/if}
                </div>
            </div>
            {#if canUpdate}
                <Button variant="ghost" size="sm" aria-label={`Edit ${job.title}`} onclick={() => edit(job)}
                    >Edit</Button
                >
                <button
                    type="button"
                    class="focus-ring inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted"
                    aria-label={`Remove ${job.title}`}
                    title={`Remove ${job.title}`}
                    onclick={() => (removing = { ...job })}
                >
                    <X class="size-4" />
                </button>
            {/if}
        </div>
    {:else}
        <p class="border-t border-border pt-3 text-[12.5px] text-muted-foreground">
            No cron jobs yet. Add one to run a command on a schedule.
        </p>
    {/each}

    {#if canUpdate && cron.jobs.length >= cron.limit}
        <p class="border-t border-border pt-2.5 text-[12.5px] text-muted-foreground">
            {`${cron.jobs.length} of ${cron.limit} jobs. Remove one to add another.`}
        </p>
    {/if}

    <p class="mt-2 text-[12px] text-muted-foreground">
        Times are UTC, the server's clock. Every job writes to <span class="font-mono">shared/logs/scheduler.log</span>.
    </p>
    {#if canUpdate || (canViewLogs && cron.live)}
        <div class="mt-auto flex flex-wrap items-center gap-x-5 gap-y-2 pt-3">
            {#if canUpdate}
                <Button variant="link" class="min-h-6" disabled={cron.jobs.length >= cron.limit} onclick={add}
                    >Add cron job</Button
                >
            {/if}
            {#if canViewLogs && cron.live}
                <Button
                    variant="link"
                    class="min-h-6"
                    aria-expanded={open}
                    aria-controls={open ? 'scheduler-log' : undefined}
                    onclick={() => (open = !open)}>{open ? 'Hide output' : 'Show output'}</Button
                >
            {/if}
        </div>
    {/if}

    {#if open}
        <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
        <div
            bind:this={panel}
            id="scheduler-log"
            class="terminal focus-ring mt-2 h-40 overflow-y-auto px-3.5 py-2.5 text-[11.5px] leading-[1.7]"
            tabindex="0"
            role="log"
            aria-live="polite"
            aria-label="Scheduler log"
            data-testid="scheduler-log"
        >
            {#if output.error}
                <div class="opacity-70">{output.error}</div>
            {:else if output.loading}
                <div class="opacity-60">reading the scheduler log…</div>
            {:else if output.lines.length === 0}
                <div class="opacity-60">Nothing in the scheduler log yet. Output appears here after a job runs.</div>
            {:else}
                {#each output.lines as line, index (index)}
                    <div class="break-all whitespace-pre-wrap">{line}</div>
                {/each}
            {/if}
        </div>
        <p class="mt-1.5 text-[12px] text-muted-foreground">The last 50 lines from every job, newest at the bottom.</p>
    {/if}
</div>

{#if canUpdate}
    <CronJobDialog bind:open={editor} job={editing} storeHref={cron.store_href} user={cron.user} {busy} {send} />

    <Dialog.Root open={removing !== null} onOpenChange={(next) => !next && (removing = null)}>
        <Dialog.Content>
            <Dialog.Header>
                <Dialog.Title>Remove this cron job?</Dialog.Title>
                <Dialog.Description
                    >{removing?.scheduler
                        ? `Laravel's scheduled tasks stop running on ${server}, and the Scheduler toggle turns off.`
                        : `${removing?.title} stops running on ${server} as soon as the change reaches it.`}</Dialog.Description
                >
            </Dialog.Header>
            <Dialog.Footer>
                <Button variant="ghost" onclick={() => (removing = null)}>Keep it</Button>
                <Button variant="destructive" disabled={busy} onclick={remove}>Remove the job</Button>
            </Dialog.Footer>
        </Dialog.Content>
    </Dialog.Root>
{/if}
