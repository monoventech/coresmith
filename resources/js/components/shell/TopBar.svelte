<script lang="ts">
    import AttentionMenu from '@/components/shell/AttentionMenu.svelte'
    import { explorerInline, showExplorer } from '@/lib/explorer.svelte'
    import type { Crumb, ExplorerProps } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { Link, page } from '@inertiajs/svelte'
    import { PanelLeftOpen, Slash } from '@lucide/svelte'

    const crumbs = $derived((page.props.crumbs ?? []) as Crumb[])
    const explorer = $derived(page.props.explorer as ExplorerProps | null)
    const inline = $derived(explorerInline())
</script>

<header class="hidden h-14 shrink-0 items-center gap-3 border-b border-border px-8 sm:flex" data-testid="top-bar">
    {#if explorer && !inline}
        <button
            type="button"
            class="focus-ring -ml-2 inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted"
            aria-label="Show the explorer"
            title="Show the explorer"
            onclick={showExplorer}
        >
            <PanelLeftOpen class="size-4" />
        </button>
    {/if}
    {#if crumbs.length > 0}
        <nav aria-label="Breadcrumb" class="min-w-0">
            <ol class="m-0 flex min-w-0 list-none items-center gap-2 p-0 text-[13px]">
                {#each crumbs as crumb, index (`${index}:${crumb.href ?? ''}:${crumb.label}`)}
                    {@const last = index === crumbs.length - 1}
                    <li class="flex min-w-0 items-center gap-2">
                        {#if index > 0}
                            <Slash class="size-3 shrink-0 text-muted-foreground/45" aria-hidden="true" />
                        {/if}
                        {#if crumb.href && !last}
                            <Link
                                href={crumb.href}
                                class={cn(
                                    'focus-ring truncate rounded-sm text-muted-foreground no-underline hover:text-foreground',
                                    crumb.mono && 'font-mono text-[12.5px]'
                                )}>{crumb.label}</Link
                            >
                        {:else}
                            <span
                                aria-current={last ? 'page' : undefined}
                                class={cn(
                                    'truncate',
                                    last ? 'font-medium text-foreground' : 'text-muted-foreground',
                                    crumb.mono && 'font-mono text-[12.5px]'
                                )}>{crumb.label}</span
                            >
                        {/if}
                    </li>
                {/each}
            </ol>
        </nav>
    {/if}
    {#if explorer}
        <AttentionMenu items={explorer.attention} />
    {/if}
</header>
