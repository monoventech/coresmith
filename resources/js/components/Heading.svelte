<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'

    let {
        title,
        description = '',
        mark = '',
        variant = 'default'
    }: {
        title: string
        description?: string
        mark?: string
        variant?: 'default' | 'small'
    } = $props()

    const markIndex = $derived(mark ? title.indexOf(mark) : -1)
    const before = $derived(markIndex >= 0 ? title.slice(0, markIndex) : title)
    const after = $derived(markIndex >= 0 ? title.slice(markIndex + mark.length) : '')

    const tag = $derived(variant === 'small' ? 'h2' : 'h1')
    const size = $derived(
        variant === 'small'
            ? 'font-display text-2xl leading-[1.2] font-semibold tracking-[-0.01em]'
            : 'font-display text-3xl leading-[1.15] font-semibold tracking-[-0.015em]'
    )
</script>

<header class={variant === 'small' ? 'space-y-1.5' : 'mb-10 space-y-2'}>
    <svelte:element this={tag} class={size}>
        {before}{#if markIndex >= 0}<MarkerUnderline>{mark}</MarkerUnderline>{/if}{after}
    </svelte:element>
    {#if description}
        <p class="max-w-2xl text-base leading-relaxed text-muted-foreground">{description}</p>
    {/if}
</header>
