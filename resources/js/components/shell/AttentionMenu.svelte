<script lang="ts">
    import * as Popover from '@/components/ui/popover'
    import type { AttentionItem } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { Link } from '@inertiajs/svelte'

    let { items }: { items: AttentionItem[] } = $props()

    let open = $state(false)

    const count = $derived(items.length)
    const label = $derived(count > 0 ? `${count} need${count === 1 ? 's' : ''} attention` : 'All systems normal')
</script>

<Popover.Root bind:open>
    <Popover.Trigger
        class="focus-ring ml-auto flex h-8 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-border bg-transparent px-2.5 text-[12.5px] font-medium text-foreground"
        data-testid="fleet-status"
    >
        <span
            class={cn('status-dot-pulse size-1.75 rounded-full', count > 0 ? 'bg-warning' : 'bg-success')}
            aria-hidden="true"
        ></span>
        {label}
    </Popover.Trigger>
    <Popover.Content align="end" class="max-h-96 w-80 overflow-y-auto p-1.5" role="dialog" aria-label="Needs attention">
        {#if count === 0}
            <p class="m-0 px-2.5 py-2 text-[13px] text-muted-foreground">Nothing needs attention.</p>
        {:else}
            <ul class="m-0 flex list-none flex-col p-0">
                {#each items as item, index (index)}
                    <li>
                        <Link
                            href={item.href}
                            class="focus-ring flex items-center gap-2.5 rounded-md px-2.5 py-2 text-[13px] text-foreground no-underline hover:bg-muted"
                            onclick={() => (open = false)}
                        >
                            <span
                                class={cn(
                                    'size-1.75 shrink-0 rounded-full',
                                    item.severity === 'critical' ? 'bg-destructive' : 'bg-warning'
                                )}
                                aria-hidden="true"
                            ></span>
                            <span class="font-mono text-[12.5px]">{item.subject}</span>
                            <span class="text-muted-foreground">{item.summary}</span>
                        </Link>
                    </li>
                {/each}
            </ul>
        {/if}
    </Popover.Content>
</Popover.Root>
