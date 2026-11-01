<script lang="ts">
    import { Link, router } from '@inertiajs/svelte'
    import { Undo2 } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { cn } from '@/lib/utils'

    type Release = {
        id: string
        release: string
        directory: string
        commit: string | null
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
        applications = [],
        releases = [],
        rollback = null,
        can
    }: {
        application: AppRef | null
        applications: { id: string; name: string; href: string; current: boolean }[]
        releases: Release[]
        rollback?: string | null
        can: { deploy: boolean }
    } = $props()

    let target = $state<Release | null>(null)
    let working = $state(false)

    const columns = 'grid grid-cols-[1.6fr_0.9fr_0.7fr_1fr_0.9fr] gap-3 max-[900px]:grid-cols-[1fr_auto]'

    $effect(() => {
        if (rollback && can.deploy) {
            const match = releases.find((release) => release.release === rollback && !release.current && release.on_disk)

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
        router.post(application.rollback_href, { release: target.release }, { preserveScroll: true, onFinish: () => ((working = false), (target = null)) })
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

<div class="mb-2 flex flex-wrap items-end justify-between gap-6">
    <div class="min-w-0">
        <h1 class="type-h1 m-0">{application ? 'Releases · ' : 'Releases'}{#if application}<span class="font-mono font-medium">{application.name}</span>{/if}</h1>
        <p class="type-body-sm mt-1.5 text-muted-foreground">Every deploy is a timestamped directory. Rollback re-points the symlink, instant, no rebuild.</p>
    </div>
    {#if application && can.deploy}
        <Button disabled={working} onclick={deploy}>Deploy</Button>
    {/if}
</div>

{#if applications.length > 1}
    <nav class="mt-4 flex flex-wrap gap-2" aria-label="Applications">
        {#each applications as each (each.id)}
            <Link
                href={each.href}
                aria-current={each.current ? 'page' : undefined}
                class={cn(
                    'focus-ring inline-flex h-8 items-center rounded-md border px-3 font-mono text-[12.5px] font-medium no-underline transition-colors duration-(--dur-fast)',
                    each.current ? 'chip-selected text-foreground' : 'border-border bg-transparent text-muted-foreground'
                )}
            >
                {each.name}
            </Link>
        {/each}
    </nav>
{/if}

{#if application?.rolled_back}
    <div class="tint-success mt-4 flex items-center gap-2.5 rounded-xl border px-4.5 py-3 text-[13.5px]" data-testid="rolled-back">
        <Undo2 class="size-4 shrink-0 text-success" />
        Rolled back. <span class="font-mono text-[12.5px]">releases/{application.rolled_back}</span> is live, the newer release stays on disk.
    </div>
{/if}

{#if !application || releases.length === 0}
    <div class="mt-5">
        <EmptyState
            title="No releases yet."
            description="A release is created every time a deploy passes every phase. Deploy an app and its releases, with tags and commits, show up here."
        >
            {#snippet action()}
                <Button variant="outline" href={route('dashboard')}>Open the fleet</Button>
            {/snippet}
        </EmptyState>
    </div>
{:else}
    <div class="mt-5 overflow-hidden rounded-xl border border-border bg-card" data-testid="releases-table">
        <div class={cn(columns, 'border-b border-border px-5.5 py-3')}>
            <span class="type-caption text-muted-foreground">Release directory</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Tag</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Branch</span>
            <span class="type-caption text-muted-foreground max-[900px]:hidden">Deployed</span>
            <span class="type-caption text-muted-foreground"></span>
        </div>
        {#each releases as release (release.id)}
            <div
                class={cn(columns, 'min-h-13 items-center border-b border-border px-5.5 py-1.5 text-[13.5px]', release.current && 'row-current')}
                data-testid="release-row"
                data-current={release.current}
            >
                <span class="block min-w-0">
                    <span class="block truncate font-mono text-[13px]">{release.directory}</span>
                    {#if release.commit}
                        <span class="mt-0.5 block text-[11.5px] text-muted-foreground">commit <span class="font-mono">{release.commit}</span>{#if release.author}{` by ${release.author}`}{/if}</span>
                    {/if}
                </span>
                <span class="inline-flex items-center gap-2 max-[900px]:hidden">
                    <span class="font-mono text-[12.5px]">{release.tag ?? '—'}</span>
                    {#if release.tag}
                        <span class={cn('text-[11px]', release.signed ? 'text-success-strong' : 'text-muted-foreground')}>{release.signed ? 'signed' : 'unsigned'}</span>
                    {/if}
                </span>
                <span class="font-mono text-[12.5px] text-muted-foreground max-[900px]:hidden">{release.branch ?? '—'}</span>
                <span class="text-muted-foreground max-[900px]:hidden" title={release.full ?? undefined}>{release.when ?? '—'}</span>
                <span class="flex justify-end">
                    {#if release.current}
                        <span class="font-mono text-[12px] font-medium text-primary-strong">current →</span>
                    {:else if !release.on_disk}
                        <span class="text-[12px] text-muted-foreground">pruned</span>
                    {:else if can.deploy}
                        <button type="button" class="focus-ring quick-action h-8! px-3! text-[12.5px]!" onclick={() => (target = release)}>Roll back to this</button>
                    {/if}
                </span>
            </div>
        {/each}
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
                The symlink re-points to {target?.directory}. It takes about a second, no rebuild, no downtime. Migrations already applied are not reversed.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (target = null)}>Cancel</Button>
            <Button disabled={working} onclick={confirmRollback}>Roll back</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
