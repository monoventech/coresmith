<script lang="ts">
    import { Link, page } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'
    import AppLogoIcon from '@/components/AppLogoIcon.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'

    let {
        title = '',
        description = '',
        children
    }: {
        title?: string
        description?: string
        children?: Snippet
    } = $props()

    const appName = $derived(page.props.appName)
</script>

<div class="relative grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
    <div
        class="grain relative hidden flex-col justify-between overflow-hidden bg-sidebar p-10 text-sidebar-foreground lg:flex"
    >
        <Link href={route('home')} class="relative z-10 flex w-fit items-center gap-2.5">
            <AppLogoIcon class="size-7 text-sidebar-primary" />
            <span class="font-display text-lg leading-none font-semibold tracking-[-0.01em]">{appName}</span>
        </Link>

        <div class="relative z-10 max-w-md">
            <p class="font-display text-3xl leading-[1.1] font-medium tracking-[-0.015em]">
                Provision servers and ship apps from your own <MarkerUnderline>machines</MarkerUnderline>.
            </p>
            <p class="mt-4 text-base leading-relaxed text-sidebar-foreground/70">
                No Docker. No lock-in. Your infrastructure, forged the way you want it.
            </p>
        </div>

        <MonovenMark class="relative z-10 text-sidebar-foreground/50" />
    </div>

    <div class="flex flex-col justify-center px-6 py-12 sm:px-10 lg:px-16">
        <div class="w-full max-w-sm">
            <Link href={route('home')} class="mb-10 flex w-fit items-center gap-2.5 lg:hidden">
                <AppLogoIcon class="size-7 text-primary" />
                <span class="font-display text-lg leading-none font-semibold tracking-[-0.01em]">{appName}</span>
            </Link>

            <div class="mb-8 space-y-2">
                {#if title}
                    <h1 class="text-3xl leading-tight font-semibold tracking-[-0.01em]">{title}</h1>
                {/if}
                {#if description}
                    <p class="text-base text-muted-foreground">{description}</p>
                {/if}
            </div>

            {@render children?.()}
        </div>

        <MonovenMark class="mt-12 lg:hidden" />
    </div>
</div>
