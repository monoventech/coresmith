<script lang="ts">
    import AppLogoIcon from '@/components/AppLogoIcon.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import { Link } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'

    let {
        title = '',
        description = '',
        children
    }: {
        title?: string
        description?: string
        children?: Snippet
    } = $props()
</script>

<div class="flex min-h-svh flex-col items-center justify-center gap-10 bg-background p-6 md:p-10">
    <div class="w-full max-w-sm">
        <Link href={route('home')} class="mb-10 flex w-fit items-center gap-2.5">
            <AppLogoIcon class="size-7 text-primary" />
            <span class="sr-only">{title}</span>
        </Link>

        <div class="mb-8 space-y-2">
            <h1 class="text-3xl leading-tight font-semibold tracking-[-0.01em]">
                <MarkerUnderline>{title}</MarkerUnderline>
            </h1>
            {#if description}
                <p class="text-base text-muted-foreground">{description}</p>
            {/if}
        </div>

        {@render children?.()}
    </div>

    <MonovenMark />
</div>
