<script lang="ts">
    import MonovenMark from '@/components/MonovenMark.svelte'
    import Wordmark from '@/components/panel/Wordmark.svelte'
    import { getInitials } from '@/lib/initials'
    import type { PanelProps, Section } from '@/lib/panel'
    import { appPath, isMac, sectionFor } from '@/lib/panel'
    import { sidebarState, toggleSidebar } from '@/lib/sidebar.svelte'
    import { themeState } from '@/lib/theme.svelte'
    import { cn } from '@/lib/utils'
    import { Link, page } from '@inertiajs/svelte'
    import {
        ChevronsUpDown,
        Layers,
        LayoutGrid,
        ListChecks,
        Moon,
        PanelLeftClose,
        PanelLeftOpen,
        Rocket,
        Search,
        Settings,
        Sun
    } from '@lucide/svelte'

    let { onSearch, onAccount }: { onSearch: () => void; onAccount: () => void } = $props()

    const sidebar = sidebarState()
    const theme = themeState()

    const panel = $derived(page.props.panel as PanelProps | null)
    const user = $derived(page.props.auth.user)
    const section = $derived(sectionFor(appPath(page.url, panel?.base)))
    const collapsed = $derived(sidebar.collapsed)
    const dark = $derived(theme.resolvedAppearance() === 'dark')
    const shortcut = isMac() ? '⌘K' : 'Ctrl K'

    type Item = { id: Section; label: string; href: string; icon: typeof LayoutGrid; badge?: string }

    const items = $derived.by((): Item[] => {
        const list: Item[] = [
            { id: 'fleet', label: 'Fleet', href: route('dashboard'), icon: LayoutGrid },
            { id: 'deployments', label: 'Deployments', href: route('deployments.index'), icon: Rocket },
            { id: 'releases', label: 'Releases', href: route('releases.index'), icon: Layers },
            { id: 'settings', label: 'Settings', href: route('settings.general'), icon: Settings }
        ]

        if (panel?.setup && !panel.setup.complete) {
            list.push({
                id: 'setup',
                label: 'Finish setup',
                href: route('setup.show'),
                icon: ListChecks,
                badge: `${panel.setup.current + 1}/${panel.setup.total}`
            })
        }

        return list
    })

    function flipTheme() {
        theme.updateAppearance(dark ? 'light' : 'dark')
    }
</script>

<aside
    class={cn(
        'panel hidden min-h-0 shrink-0 flex-col overflow-y-auto border-r border-border bg-card px-3 pt-5 pb-4 transition-[width] duration-(--dur-fast) ease-(--ease-out-soft) sm:flex',
        collapsed ? 'w-22.25' : 'w-63.25'
    )}
    data-testid="sidebar"
    data-collapsed={collapsed}
>
    <div class={cn('mb-5 flex min-h-7.5 items-center gap-2.25', collapsed ? 'justify-center' : 'px-3')}>
        {#if !collapsed}
            <Wordmark />
        {/if}
        <button
            type="button"
            class={cn(
                'focus-ring inline-flex size-7.5 shrink-0 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted',
                !collapsed && 'ml-auto'
            )}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            onclick={toggleSidebar}
        >
            {#if collapsed}
                <PanelLeftOpen class="size-4" />
            {:else}
                <PanelLeftClose class="size-4" />
            {/if}
        </button>
    </div>

    <button
        type="button"
        class={cn(
            'focus-ring mb-3 flex h-9 cursor-pointer items-center gap-2.5 rounded-md border border-border bg-background text-left text-[13px] font-medium text-muted-foreground',
            collapsed ? 'justify-center' : 'px-3'
        )}
        title="Search ({shortcut})"
        aria-label="Search"
        onclick={onSearch}
    >
        <Search class="size-3.75" />
        {#if !collapsed}
            <span class="flex-1">Search</span>
            <span class="rounded-sm border border-border px-1.25 py-px font-mono text-[11px]">{shortcut}</span>
        {/if}
    </button>

    <nav class="flex flex-col gap-0.5" aria-label="Main" data-testid="main-nav">
        {#each items as item (item.id)}
            {@const active = section === item.id}
            <Link
                href={item.href}
                title={item.label}
                aria-label={collapsed ? item.label : undefined}
                aria-current={active ? 'page' : undefined}
                class={cn(
                    'focus-ring flex h-10 w-full items-center gap-2.5 rounded-md text-[14px] font-medium no-underline transition-colors duration-(--dur-fast)',
                    collapsed ? 'justify-center' : 'px-3',
                    active ? 'bg-accent text-foreground' : 'text-muted-foreground'
                )}
            >
                <item.icon class="size-4" />
                {#if !collapsed}
                    <span class="flex-1">{item.label}</span>
                    {#if item.badge}
                        <span class={cn('font-mono text-[11px]', active ? 'text-primary-strong' : 'text-primary')}
                            >{item.badge}</span
                        >
                    {/if}
                {/if}
            </Link>
        {/each}
    </nav>

    <div class="mt-auto flex flex-col gap-3.5 px-3">
        <button
            type="button"
            class={cn(
                'focus-ring flex min-h-11 cursor-pointer items-center gap-2.5 rounded-md bg-transparent p-0 text-left text-foreground',
                collapsed && 'justify-center'
            )}
            title="{user.name}{panel?.team ? ` · ${panel.team.name}` : ''}"
            aria-label="Account"
            onclick={onAccount}
        >
            <span
                class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground"
            >
                {getInitials(user.name)}
            </span>
            {#if !collapsed}
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] font-medium">{user.name}</span>
                    {#if panel?.team}
                        <span class="block truncate text-[11px] text-muted-foreground">{panel.team.name}</span>
                    {/if}
                </span>
                <ChevronsUpDown class="size-3.5 text-muted-foreground" />
            {/if}
        </button>

        <button
            type="button"
            class={cn(
                'focus-ring flex h-9 cursor-pointer items-center gap-2.5 bg-transparent p-0 text-left text-[13px] font-medium text-muted-foreground',
                collapsed && 'justify-center'
            )}
            title={dark ? 'Light mode' : 'Dark mode'}
            aria-label={dark ? 'Light mode' : 'Dark mode'}
            onclick={flipTheme}
        >
            {#if dark}
                <Sun class="size-4" />
            {:else}
                <Moon class="size-4" />
            {/if}
            {#if !collapsed}
                <span>{dark ? 'Light mode' : 'Dark mode'}</span>
            {/if}
        </button>

        <div
            class={cn('flex items-center gap-2 text-[12px] text-muted-foreground', collapsed && 'justify-center')}
            data-testid="fleet-status"
        >
            <span
                class={cn(
                    'status-dot-pulse size-1.5 shrink-0 rounded-full',
                    panel && panel.attention > 0 ? 'bg-warning' : 'bg-success'
                )}
                aria-hidden="true"
            ></span>
            {#if !collapsed}
                <span
                    >{panel && panel.attention > 0
                        ? `${panel.attention} need${panel.attention === 1 ? 's' : ''} attention`
                        : 'All systems normal'}</span
                >
            {:else}
                <span class="sr-only"
                    >{panel && panel.attention > 0 ? `${panel.attention} need attention` : 'All systems normal'}</span
                >
            {/if}
        </div>

        {#if !collapsed}
            <MonovenMark />
        {/if}
    </div>
</aside>
