<script lang="ts">
    import type { Snippet } from 'svelte'
    import AppContent from '@/components/AppContent.svelte'
    import AppHeader from '@/components/AppHeader.svelte'
    import AppShell from '@/components/AppShell.svelte'
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

<AppShell variant="header">
    <AppHeader {breadcrumbs} />
    <AppContent variant="header">
        {@render children?.()}
        <footer class="mt-auto border-t border-border px-6 py-6">
            <MonovenMark />
        </footer>
    </AppContent>
    <Toaster />
</AppShell>
