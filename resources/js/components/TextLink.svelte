<script lang="ts">
    import { cn } from '@/lib/utils'
    import type { LinkComponentBaseProps, Method } from '@inertiajs/core'
    import { Link } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'

    let {
        href,
        tabindex,
        method,
        as,
        class: className,
        children,
        ...rest
    }: {
        href: LinkComponentBaseProps['href']
        tabindex?: number
        method?: Method
        as?: keyof HTMLElementTagNameMap
        class?: string
        children?: Snippet
        [key: string]: unknown
    } = $props()
</script>

<Link
    {href}
    {tabindex}
    {method}
    {as}
    class={cn(
        'rounded-sm text-primary underline decoration-primary/40 underline-offset-4 transition-colors outline-none',
        'hover:decoration-current focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        className
    )}
    {...rest}
>
    {@render children?.()}
</Link>
