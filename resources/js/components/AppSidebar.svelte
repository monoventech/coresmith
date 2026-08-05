<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import { BookOpen, Folder, LayoutGrid } from '@lucide/svelte'
    import NavFooter from '@/components/NavFooter.svelte'
    import NavMain from '@/components/NavMain.svelte'
    import NavUser from '@/components/NavUser.svelte'
    import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar'
    import type {NavItem} from '@/types';
    import AppLogo from './AppLogo.svelte'

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: '/dashboard',
            icon: LayoutGrid
        }
    ]

    const footerNavItems: NavItem[] = [
        {
            title: 'Repository',
            href: 'https://github.com/monoven/coresmith',
            icon: Folder
        },
        {
            title: 'Documentation',
            href: 'https://coresmith.io/docs',
            icon: BookOpen
        }
    ]
</script>

<Sidebar collapsible="icon" variant="inset">
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
        <NavFooter items={footerNavItems} class="mt-auto" />
        <NavUser />
    </SidebarFooter>
</Sidebar>
