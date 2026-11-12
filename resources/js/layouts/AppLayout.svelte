<script lang="ts">
    import MonovenMark from '@/components/MonovenMark.svelte'
    import AccountDialog from '@/components/panel/AccountDialog.svelte'
    import BillingBanner from '@/components/panel/BillingBanner.svelte'
    import CommandPalette from '@/components/panel/CommandPalette.svelte'
    import MobileNav from '@/components/panel/MobileNav.svelte'
    import Toast from '@/components/panel/Toast.svelte'
    import Explorer from '@/components/shell/Explorer.svelte'
    import Rail from '@/components/shell/Rail.svelte'
    import TopBar from '@/components/shell/TopBar.svelte'
    import * as Sheet from '@/components/ui/sheet'
    import { closeExplorer, explorerInline, explorerState, initializeExplorer } from '@/lib/explorer.svelte'
    import { getInitials } from '@/lib/initials'
    import { follow } from '@/lib/live.svelte'
    import type { ExplorerProps, PanelProps } from '@/lib/panel'
    import { appPath } from '@/lib/panel'
    import { page, router } from '@inertiajs/svelte'
    import { ChevronsUpDown, Hammer, Search } from '@lucide/svelte'
    import type { Snippet } from 'svelte'
    import { onMount, untrack } from 'svelte'

    let {
        detail = false,
        children
    }: {
        detail?: boolean
        children?: Snippet
    } = $props()

    let palette = $state(false)
    let account = $state(false)

    const shell = explorerState()
    const user = $derived(page.props.auth.user)
    const billing = $derived(page.props.billing)
    const panel = $derived(page.props.panel as PanelProps | null)
    const explorer = $derived(page.props.explorer as ExplorerProps | null)
    const inline = $derived(explorerInline())
    const onBilling = $derived(appPath(page.url, panel?.base).startsWith('/settings/billing'))
    const live = $derived(
        `${panel?.team?.id ?? ''}:${panel?.live.streaming ? 'on' : 'off'}:${panel?.live.socket?.key ?? ''}`
    )

    $effect(() => {
        if (live.startsWith(':')) {
            return
        }

        return untrack(() => follow({ only: ['explorer', 'panel'], async: true }))
    })

    onMount(() => {
        initializeExplorer()

        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault()
                palette = !palette
            }
        }

        window.addEventListener('keydown', onKey)
        const stop = router.on('navigate', closeExplorer)

        return () => {
            window.removeEventListener('keydown', onKey)
            stop()
        }
    })
</script>

<div class="panel flex h-dvh overflow-hidden bg-background font-sans text-foreground">
    <Rail onAccount={() => (account = true)} />

    {#if explorer && inline}
        <div class="hidden sm:flex">
            <Explorer {explorer} onSearch={() => (palette = true)} onAccount={() => (account = true)} />
        </div>
    {/if}

    <div class="flex min-w-0 flex-1 flex-col">
        <TopBar />

        <main
            id="main"
            {...{ 'scroll-region': '' }}
            class="grain relative min-w-0 flex-1 overflow-y-auto"
            data-testid="panel-main"
        >
            <div class="mx-auto flex min-h-full max-w-302 flex-col px-4.5 pt-3 pb-24 sm:px-10 sm:pt-8 sm:pb-8">
                {#if !detail}
                    <div class="mt-2 mb-0.5 flex items-center gap-2 sm:hidden">
                        <span
                            class="tint-primary inline-flex size-8.5 shrink-0 items-center justify-center rounded-lg border text-primary"
                            aria-hidden="true"
                        >
                            <Hammer class="size-4.5" />
                        </span>
                        {#if explorer}
                            <button
                                type="button"
                                class="focus-ring flex min-h-11 min-w-0 cursor-pointer items-center gap-1.5 rounded-md bg-transparent px-1 text-[15px] font-semibold"
                                onclick={() => (account = true)}
                            >
                                <span class="truncate">{explorer.team.name}</span>
                                <ChevronsUpDown class="size-3.75 shrink-0 text-muted-foreground" />
                            </button>
                        {/if}
                        <button
                            type="button"
                            class="focus-ring ml-auto inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-lg bg-transparent text-muted-foreground"
                            aria-label="Search"
                            onclick={() => (palette = true)}
                        >
                            <Search class="size-4.75" />
                        </button>
                        <button
                            type="button"
                            class="focus-ring inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-full bg-transparent"
                            aria-label="Account"
                            onclick={() => (account = true)}
                        >
                            <span
                                class="inline-flex size-8 items-center justify-center rounded-full border border-border bg-muted text-[11px] font-semibold text-muted-foreground"
                            >
                                {getInitials(user.name)}
                            </span>
                        </button>
                    </div>
                {/if}

                {#if billing?.banner && !onBilling}
                    <BillingBanner banner={billing.banner} href={billing.href} />
                {/if}

                {@render children?.()}

                <footer class="mt-auto flex justify-end pt-12 max-sm:pt-8">
                    <MonovenMark />
                </footer>
            </div>
        </main>
    </div>

    {#if explorer && !shell.wide}
        <Sheet.Root bind:open={shell.open}>
            <Sheet.Content side="left" showCloseButton={false} class="w-72 max-w-72 gap-0 border-r-0 p-0 sm:max-w-72">
                <Sheet.Title class="sr-only">Explorer</Sheet.Title>
                <Explorer {explorer} onSearch={() => (palette = true)} onAccount={() => (account = true)} />
            </Sheet.Content>
        </Sheet.Root>
    {/if}

    <MobileNav />
    <CommandPalette bind:open={palette} />
    <AccountDialog bind:open={account} />
    <Toast />
</div>
