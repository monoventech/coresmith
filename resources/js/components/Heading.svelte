<script lang="ts">
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
</script>

<header class={variant === 'small' ? 'space-y-1' : 'mb-10 space-y-1.5'}>
    <h2 class={variant === 'small' ? 'text-xl font-semibold' : 'text-2xl font-semibold tracking-[-0.01em]'}>
        {before}{#if markIndex >= 0}<MarkerUnderline>{mark}</MarkerUnderline>{/if}{after}
    </h2>
    {#if description}
        <p class="text-sm text-muted-foreground">{description}</p>
    {/if}
</header>
