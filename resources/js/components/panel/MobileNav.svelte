<script lang="ts">
    import type { PanelProps } from '@/lib/panel'
    import { appPath, sectionFor } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { Link, page } from '@inertiajs/svelte'
    import { Layers, LayoutGrid, Rocket, Settings } from '@lucide/svelte'

    const section = $derived(sectionFor(appPath(page.url, (page.props.panel as PanelProps | null)?.base)))

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
                'focus-ring flex min-h-11 min-w-14 flex-col items-center justify-center gap-1 rounded-md py-1.5 text-[10.5px] font-medium no-underline',
                active ? 'text-primary' : 'text-muted-foreground'
            )}
        >
            <item.icon class="size-5" />
            {item.label}
        </Link>
    {/each}
</nav>
