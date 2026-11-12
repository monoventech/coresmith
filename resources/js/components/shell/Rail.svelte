<script lang="ts">
    import { getInitials } from '@/lib/initials'
    import type { ExplorerProps, PanelProps, Section } from '@/lib/panel'
    import { appPath, sectionFor } from '@/lib/panel'
    import { themeState } from '@/lib/theme.svelte'
    import { cn } from '@/lib/utils'
    import { Link, page } from '@inertiajs/svelte'
    import { Hammer, Layers, LayoutGrid, ListChecks, Moon, Rocket, Settings, Sun } from '@lucide/svelte'

    let { onAccount }: { onAccount: () => void } = $props()

    const theme = themeState()

    const panel = $derived(page.props.panel as PanelProps | null)
    const explorer = $derived(page.props.explorer as ExplorerProps | null)
    const user = $derived(page.props.auth.user)
    const section = $derived(sectionFor(appPath(page.url, panel?.base)))
    const dark = $derived(theme.resolvedAppearance() === 'dark')

    type Item = {
        id: Section
        label: string
        href: string
        icon: typeof LayoutGrid
        name?: string
        badge?: string
    }

    const items = $derived.by((): Item[] => {
        const list: Item[] = [
            { id: 'fleet', label: 'Fleet', href: route('dashboard'), icon: LayoutGrid },
            { id: 'deployments', label: 'Deploys', href: route('deployments.index'), icon: Rocket },
            { id: 'releases', label: 'Releases', href: route('releases.index'), icon: Layers },
            { id: 'settings', label: 'Settings', href: route('settings.general'), icon: Settings }
        ]

        if (panel?.setup && !panel.setup.complete) {
            list.push({
                id: 'setup',
                label: 'Setup',
                name: `Finish setup, step ${panel.setup.current + 1} of ${panel.setup.total}`,
                href: route('setup.show'),
                icon: ListChecks,
                badge: `${panel.setup.current + 1}/${panel.setup.total}`
            })
        }

        return list
    })
</script>

<nav
    aria-label="Main"
    class="panel hidden w-18 shrink-0 flex-col items-center gap-1.5 overflow-y-auto border-r border-border bg-card pt-3.5 pb-4 sm:flex"
    data-testid="rail"
>
    <Link
        href={route('dashboard')}
        aria-label="Coresmith"
        title="Coresmith"
        class="focus-ring tint-primary mb-3.5 inline-flex size-10 shrink-0 items-center justify-center rounded-lg border text-primary no-underline"
    >
        <Hammer class="size-5" />
    </Link>

    {#each items as item (item.id)}
        {@const active = section === item.id}
        <Link
            href={item.href}
            aria-current={active ? 'page' : undefined}
            aria-label={item.name}
            title={item.name ?? item.label}
            class={cn(
                'focus-ring relative flex w-14 shrink-0 flex-col items-center gap-1 rounded-lg pt-2 pb-1.5 no-underline transition-colors duration-(--dur-fast)',
                active ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-muted'
            )}
        >
            {#if active}
                <span class="absolute top-3 -left-2 h-5 w-0.75 rounded-r-sm bg-primary" aria-hidden="true"></span>
            {/if}
            <item.icon class="size-5" />
            <span class="text-[11px] font-medium">{item.label}</span>
            {#if item.badge}
                <span class="font-mono text-[10.5px] text-primary-strong">{item.badge}</span>
            {/if}
            {#if item.id === 'deployments' && explorer?.running}
                <span
                    class="status-dot-working absolute top-1.5 right-3 size-1.75 rounded-full bg-primary"
                    aria-hidden="true"
                    data-testid="deploys-live"
                ></span>
            {/if}
        </Link>
    {/each}

    <div class="mt-auto flex flex-col items-center gap-2.5 pt-3">
        <button
            type="button"
            class="focus-ring inline-flex size-10 cursor-pointer items-center justify-center rounded-lg bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted"
            aria-label={dark ? 'Light mode' : 'Dark mode'}
            title={dark ? 'Light mode' : 'Dark mode'}
            onclick={() => theme.updateAppearance(dark ? 'light' : 'dark')}
        >
            {#if dark}
                <Sun class="size-4.5" />
            {:else}
                <Moon class="size-4.5" />
            {/if}
        </button>
        <button
            type="button"
            class="focus-ring inline-flex size-9 cursor-pointer items-center justify-center rounded-full border border-border bg-muted text-[12px] font-semibold text-muted-foreground"
            aria-label="Account"
            title={user.name}
            onclick={onAccount}
        >
            {getInitials(user.name)}
        </button>
    </div>
</nav>
