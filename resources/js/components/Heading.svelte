<script lang="ts">
    let {
        title,
        description = '',
        variant = 'default'
    }: {
        title: string
        description?: string
        variant?: 'default' | 'small'
    } = $props()
</script>

<header class={variant === 'small' ? 'space-y-1' : 'mb-10 space-y-1.5'}>
    <h2 class={variant === 'small' ? 'text-xl font-semibold' : 'text-2xl font-semibold tracking-[-0.01em]'}>
        {title}
    </h2>
    {#if description}
        <p class="text-sm text-muted-foreground">{description}</p>
    {/if}
</header>
