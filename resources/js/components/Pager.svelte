<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import { ChevronLeft, ChevronRight } from '@lucide/svelte'
    import { buttonVariants } from '@/components/ui/button'
    import type { Pages } from '@/lib/paging'
    import { cn } from '@/lib/utils'

    let {
        pages,
        label,
        noun,
        only = undefined,
        preserveScroll = false,
        class: className = ''
    }: {
        pages: Pages
        label: string
        noun: string
        only?: string[]
        preserveScroll?: boolean
        class?: string
    } = $props()

    const range = $derived(
        pages.total === null
            ? `Page ${pages.current}`
            : pages.from === pages.to
              ? `${pages.from} of ${pages.total} ${noun}`
              : `${pages.from}–${pages.to} of ${pages.total} ${noun}`
    )
    const step = cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'max-sm:flex-1')
</script>

{#if pages.newer || pages.older}
    <nav
        aria-label={label}
        class={cn('flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5', className)}
        data-testid="pager"
    >
        <p class="m-0 text-[12.5px] text-muted-foreground" data-testid="pager-range">{range}</p>
        <div class="flex items-center gap-1.5 max-sm:w-full">
            {#if pages.newer}
                <Link href={pages.newer} {only} {preserveScroll} rel="prev" class={step} data-testid="pager-newer"
                    ><ChevronLeft aria-hidden="true" />Newer</Link
                >
            {:else}
                <span aria-hidden="true" class={cn(step, 'pointer-events-none opacity-50')}><ChevronLeft />Newer</span>
            {/if}
            {#each pages.links as link, index (link ? `page-${link.page}` : `gap-${index}`)}
                {#if link}
                    <Link
                        href={link.href}
                        {only}
                        {preserveScroll}
                        aria-label="Page {link.page}"
                        aria-current={link.page === pages.current ? 'page' : undefined}
                        class={cn(
                            buttonVariants({
                                variant: link.page === pages.current ? 'outline' : 'ghost',
                                size: 'icon-sm'
                            }),
                            'font-mono text-[12.5px] max-sm:hidden'
                        )}>{link.page}</Link
                    >
                {:else}
                    <span aria-hidden="true" class="w-5 text-center text-muted-foreground max-sm:hidden">…</span>
                {/if}
            {/each}
            {#if pages.older}
                <Link href={pages.older} {only} {preserveScroll} rel="next" class={step} data-testid="pager-older"
                    >Older<ChevronRight aria-hidden="true" /></Link
                >
            {:else}
                <span aria-hidden="true" class={cn(step, 'pointer-events-none opacity-50')}>Older<ChevronRight /></span>
            {/if}
        </div>
    </nav>
{/if}
