<script lang="ts">
    import type { ActivityItem } from '@/lib/fleet'
    import { cn } from '@/lib/utils'
    import { Link } from '@inertiajs/svelte'

    let { item, arrow = false, last = false }: { item: ActivityItem; arrow?: boolean; last?: boolean } = $props()

    const rowClass = 'flex w-full items-baseline gap-3 text-left text-foreground no-underline'
</script>

{#snippet content()}
    <span
        class="w-13 flex-none font-mono text-[12px] text-muted-foreground"
        title={new Date(item.at).toLocaleString()}>{item.time}</span
    >
    <span class="flex-1 text-[13.5px]">
        {#each item.parts as part, index (index)}{#if part.mono}<span class="font-mono">{part.text}</span
                >{:else}{part.text}{/if}{/each}
    </span>
    {#if arrow}
        <span class="text-[12px] text-muted-foreground" aria-hidden="true">→</span>
    {/if}
{/snippet}

{#if arrow}
    {#if item.href}
        <Link
            href={item.href}
            class={cn(
                rowClass,
                'focus-ring border-b border-border px-0.5 py-2.25 transition-colors duration-(--dur-fast) hover:bg-muted'
            )}
        >
            {@render content()}
        </Link>
    {:else}
        <div class={cn(rowClass, 'border-b border-border px-0.5 py-2.25')}>
            {@render content()}
        </div>
    {/if}
{:else}
    <div class={cn(rowClass, 'py-2.75', !last && 'border-b border-border')}>
        {@render content()}
    </div>
{/if}
