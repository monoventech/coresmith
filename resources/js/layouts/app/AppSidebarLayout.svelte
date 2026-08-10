<script lang="ts">
    import type { Snippet } from 'svelte'
    import AppContent from '@/components/AppContent.svelte'
    import AppShell from '@/components/AppShell.svelte'
    import AppSidebar from '@/components/AppSidebar.svelte'
    import AppSidebarHeader from '@/components/AppSidebarHeader.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import { Toaster } from '@/components/ui/sonner'
    import type { BreadcrumbItem } from '@/types'

    let {
        breadcrumbs = [],
        children
    }: {
        breadcrumbs?: BreadcrumbItem[]
        children?: Snippet
    } = $props()
</script>

<AppShell variant="sidebar">
    <AppSidebar />
    <AppContent variant="sidebar" class="overflow-x-hidden">
        <AppSidebarHeader {breadcrumbs} />
        <div class="mx-auto w-full max-w-[80rem] flex-1">
            {@render children?.()}
        </div>
        <footer class="mt-auto border-t border-border px-6 py-6">
            <MonovenMark />
        </footer>
    </AppContent>
    <Toaster />
</AppShell>
