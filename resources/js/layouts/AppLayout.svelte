<script lang="ts">
    import AccountDialog from '@/components/panel/AccountDialog.svelte'
    import BillingBanner from '@/components/panel/BillingBanner.svelte'
    import CommandPalette from '@/components/panel/CommandPalette.svelte'
    import MobileNav from '@/components/panel/MobileNav.svelte'
    import Sidebar from '@/components/panel/Sidebar.svelte'
    import Toast from '@/components/panel/Toast.svelte'
    import Wordmark from '@/components/panel/Wordmark.svelte'
    import { getInitials } from '@/lib/initials'
    import type { PanelProps } from '@/lib/panel'
    import { appPath } from '@/lib/panel'
    import { initializeSidebar } from '@/lib/sidebar.svelte'
    import { page } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'
    import { onMount } from 'svelte'

    let {
        detail = false,
        children
    }: {
        detail?: boolean
        children?: Snippet
    } = $props()

    let palette = $state(false)
    let account = $state(false)

    const user = $derived(page.props.auth.user)
    const billing = $derived(page.props.billing)
    const onBilling = $derived(
        appPath(page.url, (page.props.panel as PanelProps | null)?.base).startsWith('/settings/billing')
    )

    onMount(() => {
        initializeSidebar()

        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault()
                palette = !palette
            }
        }

        window.addEventListener('keydown', onKey)

        return () => window.removeEventListener('keydown', onKey)
    })
</script>

<div class="panel flex h-dvh overflow-hidden bg-background font-sans text-foreground">
    <Sidebar onSearch={() => (palette = true)} onAccount={() => (account = true)} />

    <main
        id="main"
        {...{ 'scroll-region': '' }}
        class="grain relative min-w-0 flex-1 overflow-y-auto"
        data-testid="panel-main"
    >
        <div class="mx-auto max-w-302 px-4.5 pt-3 pb-24 sm:px-11 sm:pt-9 sm:pb-16">
            {#if !detail}
                <div class="mt-2 mb-0.5 flex items-center justify-between gap-3 sm:hidden">
                    <Wordmark size="compact" />
                    <button
                        type="button"
                        class="focus-ring inline-flex size-11 cursor-pointer items-center justify-center rounded-full bg-transparent"
                        aria-label="Account"
                        onclick={() => (account = true)}
                    >
                        <span
                            class="inline-flex size-7 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground"
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
        </div>
    </main>

    <MobileNav />
    <CommandPalette bind:open={palette} />
    <AccountDialog bind:open={account} />
    <Toast />
</div>
