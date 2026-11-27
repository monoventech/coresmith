<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import { Undo2 } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Pager from '@/components/Pager.svelte'
    import AppSwitcher from '@/components/releases/AppSwitcher.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { Pages } from '@/lib/paging'
    import { cn } from '@/lib/utils'

    type Release = {
        id: string
        release: string
        directory: string
        commit: string | null
        subject: string | null
        author: string | null
        tag: string | null
        signed: boolean | null
        branch: string | null
        when: string | null
        full: string | null
        current: boolean
        on_disk: boolean
    }

    type AppRef = {
        id: string
        name: string
        server: string
        href: string
        deploy_href: string
        rollback_href: string
        rolled_back: string | null
    }

    let {
        application,
        apps_href: appsHref = route('releases.apps'),
        overview_href: overviewHref = route('releases.index'),
        releases = [],
        pages = null,
        rollback = null,
        can
    }: {
        application: AppRef | null
        apps_href?: string
        overview_href?: string
        releases: Release[]
        pages?: Pages | null
        rollback?: string | null
        can: { deploy: boolean }
    } = $props()

    let target = $state<Release | null>(null)
    let working = $state(false)

    const tagged = $derived(releases.some((release) => release.tag !== null))
    const columns = $derived(
        cn(
            'grid gap-3 max-[900px]:grid-cols-[minmax(0,1fr)_auto]',
            tagged
                ? 'grid-cols-[minmax(0,1.6fr)_minmax(0,0.9fr)_minmax(0,0.7fr)_minmax(0,1fr)_minmax(0,0.9fr)]'
                : 'grid-cols-[minmax(0,2.5fr)_minmax(0,0.7fr)_minmax(0,1fr)_minmax(0,0.9fr)]'
        )
    )

    $effect(() => {
        if (rollback && can.deploy) {
            const match = releases.find(
                (release) => release.release === rollback && !release.current && release.on_disk
            )

            if (match) {
                target = match
            }
        }
    })

    function confirmRollback() {
        if (!application || !target) {
            return
        }

        working = true
        router.post(
            application.rollback_href,
            { release: target.release },
            { preserveScroll: true, onFinish: () => ((working = false), (target = null)) }
        )
    }

    function deploy() {
        if (!application) {
            return
        }

        working = true
        router.post(application.deploy_href, { from: 'releases' }, { onFinish: () => (working = false) })
    }
</script>

<AppHead title={application ? `Releases · ${application.name}` : 'Releases'} />

<BackLink href={overviewHref} label="Releases" />

<div class="mb-2 flex flex-wrap items-end justify-between gap-6">
    <div class="min-w-0">
        <div class="flex min-w-0 items-start gap-1">
            <h1 class="type-h1 m-0 min-w-0 break-words">
                {application ? 'Releases · ' : 'Releases'}{#if application}<span class="font-mono font-medium"
                        >{application.name}</span
                    >{/if}
            </h1>
            {#if application}
                <span class="mt-0.5 shrink-0"><AppSwitcher href={appsHref} current={application.id} /></span>
            {/if}
        </div>
        <p class="type-body-sm mt-1.5 text-muted-foreground">
            Every deploy gets its own timestamped folder. Rolling back switches to an older one, so nothing is rebuilt.
        </p>
    </div>
    {#if application && can.deploy}
        <Button disabled={working} onclick={deploy} class="sm:hidden">Deploy</Button>
    {/if}
</div>

{#if application?.rolled_back}
    <div
        class="tint-success mt-4 flex items-start gap-2.5 rounded-xl border px-4.5 py-3 text-[13.5px]"
        data-testid="rolled-back"
    >
        <Undo2 class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
        <span class="min-w-0"
            >Rolled back. <span class="font-mono text-[12.5px] break-all">releases/{application.rolled_back}</span> is live.
            The newer release stays on disk.</span
        >
    </div>
{/if}

{#if !application || releases.length === 0}
    <div class="mt-5">
        <EmptyState
            title="No releases yet."
            description="A release is created every time a deploy passes every phase. Deploy an app and its releases, with tags and commits, show up here."
        >
            {#snippet action()}
                {#if application && can.deploy}
                    <Button disabled={working} onclick={deploy}>Deploy</Button>
                {:else}
                    <Button variant="outline" href={application?.href ?? route('dashboard')}
                        >{application ? `Open ${application.name}` : 'Open the fleet'}</Button
                    >
                {/if}
            {/snippet}
        </EmptyState>
    </div>
{:else}
    <div class="mt-5 overflow-hidden rounded-xl border border-border bg-card" data-testid="releases-table">
        <div class={cn(columns, 'border-b border-border px-5.5 py-3')}>
            <span class="type-caption text-muted-foreground">Release directory</span>
            {#if tagged}
                <span class="type-caption text-muted-foreground max-[900px]:hidden">Tag</span>
            {/if}
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Branch</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Deployed</span>
            <span class="type-caption text-muted-foreground"></span>
        </div>
        {#each releases as release (release.id)}
            <div
                class={cn(
                    columns,
                    'min-h-13 items-center border-b border-border px-5.5 py-1.5 text-[13.5px]',
                    release.current && 'row-current'
                )}
                data-testid="release-row"
                data-current={release.current}
            >
                <span class="block min-w-0">
                    <span class="block truncate font-mono text-[13px]"
                        ><span class="max-sm:hidden">releases/</span>{release.directory.replace(
                            /^releases\//,
                            ''
                        )}</span
                    >
                    {#if release.subject || release.commit}
                        <span
                            class="mt-0.5 flex min-w-0 items-baseline gap-1.5 text-[11.5px] text-muted-foreground max-[900px]:line-clamp-2 max-[900px]:block"
                        >
                            {#if release.subject}
                                <span
                                    class="min-w-0 truncate text-foreground/80 max-[900px]:inline max-[900px]:whitespace-normal"
                                    data-testid="release-subject">{release.subject}</span
                                >
                            {/if}
                            {#if release.commit}
                                <span
                                    class="shrink-0 whitespace-nowrap max-[900px]:inline max-[900px]:whitespace-normal"
                                    >{release.subject ? '· ' : ''}commit
                                    <span class="font-mono">{release.commit}</span
                                    >{#if release.author}{` by ${release.author}`}{/if}</span
                                >
                            {/if}
                        </span>
                    {/if}
                    <span class="mt-0.5 hidden text-[11.5px] text-muted-foreground max-[900px]:block"
                        >{[release.tag, release.branch, release.when].filter(Boolean).join(' · ')}</span
                    >
                </span>
                {#if tagged}
                    <span class="inline-flex items-center gap-2 max-[900px]:hidden">
                        <span class="font-mono text-[12.5px]">{release.tag ?? '—'}</span>
                        {#if release.tag}
                            <span
                                class={cn(
                                    'text-[11px]',
                                    release.signed ? 'text-success-strong' : 'text-muted-foreground'
                                )}>{release.signed ? 'signed' : 'unsigned'}</span
                            >
                        {/if}
                    </span>
                {/if}
                <span class="font-mono text-[12.5px] text-muted-foreground max-[900px]:hidden"
                    >{release.branch ?? '—'}</span
                >
                <span class="text-muted-foreground max-[900px]:hidden" title={release.full ?? undefined}
                    >{release.when ?? '—'}</span
                >
                <span class="flex justify-end">
                    {#if release.current}
                        <span class="font-mono text-[12px] font-medium text-primary-strong">current →</span>
                    {:else if !release.on_disk}
                        <span class="text-[12px] text-muted-foreground">pruned</span>
                    {:else if can.deploy}
                        <button
                            type="button"
                            class="focus-ring quick-action h-8! px-3! text-[12.5px]!"
                            onclick={() => (target = release)}>Roll back to this</button
                        >
                    {/if}
                </span>
            </div>
        {/each}
        {#if pages}
            <Pager
                {pages}
                label="Release pages"
                noun="releases"
                class="border-b border-border px-5.5 py-3 max-sm:px-4"
            />
        {/if}
        <p class="m-0 px-5.5 py-3 text-[12.5px] text-muted-foreground">
            The last five releases stay on disk for instant rollback. Older ones are pruned after each deploy.
        </p>
    </div>
{/if}

<Dialog.Root open={target !== null} onOpenChange={(open) => !open && (target = null)}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Roll back to {target?.tag ?? target?.directory}?</Dialog.Title>
            <Dialog.Description>
                <span class="whitespace-nowrap">{target?.directory}</span> goes live again in about a second, and nothing
                is rebuilt. Migrations that already ran are not undone.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (target = null)}>Cancel</Button>
            <Button disabled={working} onclick={confirmRollback}>Roll back</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
