<script lang="ts">
    import Heading from '@/components/Heading.svelte'
    import { Separator } from '@/components/ui/separator'
    import { currentUrlState } from '@/lib/currentUrl.svelte'
    import { cn } from '@/lib/utils'
    import type { NavItem } from '@/types'
    import { Link } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'

    let {
        children
    }: {
        children?: Snippet
    } = $props()

    const sidebarNavItems: NavItem[] = [
        {
            title: 'Profile',
            href: route('profile.edit')
        },
        {
            title: 'Security',
            href: route('security.edit')
        },
        {
            title: 'Appearance',
            href: route('appearance.edit')
        }
    ]

    const url = currentUrlState()
</script>

<div class="px-6 py-8">
    <Heading title="Settings" description="Manage your profile and account settings" />

    <div class="flex flex-col gap-10 lg:flex-row lg:gap-16">
        <aside class="w-full lg:w-52 lg:shrink-0">
            <nav class="flex flex-col gap-0.5" aria-label="Settings">
                {#each sidebarNavItems as item (item.href)}
                    {@const isCurrent = url.isCurrentUrl(item.href, url.currentUrl)}
                    <Link
                        href={item.href}
                        aria-current={isCurrent ? 'page' : undefined}
                        class={cn(
                            'relative rounded-md px-3 py-2 text-sm font-medium transition-colors outline-none',
                            'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                            isCurrent
                                ? 'bg-accent text-foreground before:absolute before:inset-y-1.5 before:-left-px before:w-0.5 before:rounded-full before:bg-primary'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        )}
                    >
                        {item.title}
                    </Link>
                {/each}
            </nav>
        </aside>

        <Separator class="lg:hidden" />

        <div class="flex-1">
            <section class="max-w-xl space-y-12">
                {@render children?.()}
            </section>
        </div>
    </div>
</div>
