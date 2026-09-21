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
        'focus-ring cursor-pointer rounded-sm bg-transparent p-0 font-medium text-primary no-underline underline-offset-4 hover:underline',
        className
    )}
    {...rest}
>
    {@render children?.()}
</Link>
