<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import { Database, LayoutGrid, LayoutTemplate, Server } from '@lucide/svelte'
    import NavMain from '@/components/NavMain.svelte'
    import NavUser from '@/components/NavUser.svelte'
    import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar'
    import type {NavItem} from '@/types';
    import AppLogo from './AppLogo.svelte'

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: route('dashboard'),
            icon: LayoutGrid
        },
        {
            title: 'Servers',
            href: route('servers.index'),
            icon: Server
        },
        {
            title: 'Applications',
            href: route('applications.index'),
            icon: LayoutTemplate
        },
        {
            title: 'Databases',
            href: route('databases.index'),
            icon: Database
        }
    ]

</script>

<Sidebar collapsible="icon" variant="sidebar">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg">
                    {#snippet child({ props })}
                        <Link href={route('dashboard')} {...props}>
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavUser />
    </SidebarFooter>
</Sidebar>
