<script lang="ts">
    import { page } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'
    import { SidebarProvider } from '@/components/ui/sidebar'

    interface Props {
        variant?: 'header' | 'sidebar'
        class?: string
        children?: Snippet
    }

    let { variant = 'sidebar', class: className, children }: Props = $props()

    const isOpen = $derived(page.props.sidebarOpen)
</script>

{#if variant === 'header'}
    <div class="flex min-h-screen w-full flex-col {className}">
        {@render children?.()}
    </div>
{:else}
    <SidebarProvider open={isOpen}>
        {@render children?.()}
    </SidebarProvider>
{/if}
