<script lang="ts">
    import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar'
    import { currentUrlState } from '@/lib/currentUrl.svelte'
    import type { NavItem } from '@/types'
    import { Link } from '@inertiajs/svelte'

    interface Props {
        items: NavItem[]
    }

    let { items = [] }: Props = $props()

    const url = currentUrlState()
</script>

<SidebarGroup class="px-2 py-0" data-testid="main-nav">
    <SidebarGroupLabel>Infrastructure</SidebarGroupLabel>
    <SidebarMenu>
        {#each items as item (item.title)}
            <SidebarMenuItem>
                <SidebarMenuButton isActive={url.isCurrentUrl(item.href, url.currentUrl)}>
                    {#snippet child({ props })}
                        <Link href={item.href} {...props}>
                            {#if item.icon}
                                {@const Icon = item.icon}
                                <Icon class="h-4 w-4 shrink-0" />
                            {/if}
                            <span>{item.title}</span>
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        {/each}
    </SidebarMenu>
</SidebarGroup>
