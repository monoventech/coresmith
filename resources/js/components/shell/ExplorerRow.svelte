<script lang="ts">
    import AppTags from '@/components/projects/AppTags.svelte'
    import { statusDot } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { Link } from '@inertiajs/svelte'
    import { Database } from '@lucide/svelte'

    let {
        href,
        name,
        detail,
        aside = null,
        asideClass = '',
        tags = [],
        tone = '',
        database = false,
        indent = false,
        current = false
    }: {
        href: string
        name: string
        detail: string
        aside?: string | null
        asideClass?: string
        tags?: string[]
        tone?: string
        database?: boolean
        indent?: boolean
        current?: boolean
    } = $props()
</script>

<Link
    {href}
    aria-label={[name, ...tags, detail].join(', ')}
    title={[name, ...tags, detail].join(' · ')}
    aria-current={current ? 'page' : undefined}
    class={cn(
        'focus-ring flex h-7.5 min-w-0 items-center gap-2 rounded-md pr-2 no-underline transition-colors duration-(--dur-fast) pointer-coarse:h-11',
        indent ? 'pl-9.5' : 'pl-3.5',
        current ? 'bg-accent text-foreground' : 'text-foreground hover:bg-muted'
    )}
>
    {#if database}
        <Database class="size-3.25 shrink-0 text-muted-foreground" />
    {:else}
        <span class={cn('size-2 shrink-0 rounded-full', statusDot(tone))} aria-hidden="true"></span>
    {/if}
    <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]">{name}</span>
    {#if tags.length > 0}
        <span class="flex min-w-0 shrink-0 justify-end" aria-hidden="true">
            <AppTags {tags} limit={2} class="flex-nowrap" chipClass="max-w-20" />
        </span>
    {:else if aside}
        <span class={cn('shrink-0 truncate text-[11px] text-muted-foreground', asideClass)} aria-hidden="true"
            >{aside}</span
        >
    {/if}
</Link>
