<script lang="ts">
    import type { ExplorerProps, PanelProps } from '@/lib/panel'
    import { appPath, sectionFor } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { Link, page } from '@inertiajs/svelte'
    import { Layers, LayoutGrid, Rocket, Settings } from '@lucide/svelte'

    const section = $derived(sectionFor(appPath(page.url, (page.props.panel as PanelProps | null)?.base)))
    const running = $derived((page.props.explorer as ExplorerProps | null)?.running ?? null)

    const items = [
        { id: 'fleet', label: 'Fleet', href: route('dashboard'), icon: LayoutGrid },
        { id: 'deployments', label: 'Deploys', href: route('deployments.index'), icon: Rocket },
        { id: 'releases', label: 'Releases', href: route('releases.index'), icon: Layers },
        { id: 'settings', label: 'Settings', href: route('settings.general'), icon: Settings }
    ] as const
</script>

<nav
    class="panel fixed inset-x-0 bottom-0 z-40 flex justify-around gap-2 border-t border-border bg-background px-4.5 pt-2 pb-[max(env(safe-area-inset-bottom),0.5rem)] sm:hidden"
    aria-label="Main"
    data-testid="mobile-nav"
>
    {#each items as item (item.id)}
        {@const active = section === item.id}
        <Link
            href={item.href}
            aria-current={active ? 'page' : undefined}
            class={cn(
                'focus-ring relative flex min-h-11 min-w-14 flex-col items-center justify-center gap-1 rounded-md py-1.5 text-[10.5px] font-medium no-underline',
                active ? 'text-primary-strong' : 'text-muted-foreground'
            )}
        >
            {#if active}
                <span class="absolute -top-2 left-1/4 h-0.5 w-1/2 rounded-b-sm bg-primary" aria-hidden="true"></span>
            {/if}
            <item.icon class="size-5" />
            {item.label}
            {#if item.id === 'deployments' && running}
                <span
                    class="status-dot-working absolute top-1 right-3 size-1.75 rounded-full bg-primary"
                    aria-hidden="true"
                    data-testid="deploys-live"
                ></span>
            {/if}
        </Link>
    {/each}
</nav>
