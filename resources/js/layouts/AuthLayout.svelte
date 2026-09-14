<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import Toast from '@/components/panel/Toast.svelte'
    import Wordmark from '@/components/panel/Wordmark.svelte'

    let {
        title = '',
        description = '',
        mark = '',
        children
    }: {
        title?: string
        description?: string
        mark?: string
        children?: Snippet
    } = $props()

    const word = $derived(mark || title.split(' ').at(-1) || '')
    const index = $derived(word ? title.lastIndexOf(word) : -1)
    const before = $derived(index >= 0 ? title.slice(0, index) : title)
    const after = $derived(index >= 0 ? title.slice(index + word.length) : '')
</script>

<div class="panel grain relative flex min-h-dvh flex-col bg-background font-sans text-foreground">
    <header class="relative z-10 mx-auto flex w-full max-w-[1200px] items-center px-10 py-5.5 max-sm:px-5">
        <Link href={route('home')} class="focus-ring rounded-md text-foreground no-underline" aria-label="Coresmith home">
            <Wordmark />
        </Link>
    </header>

    <main class="relative z-10 mx-auto w-full max-w-[1200px] flex-1 px-10 pt-16 pb-12 max-sm:px-5 max-sm:pt-8">
        <div class="max-w-[420px]">
            {#if title}
                <h1 class="type-h1 m-0 mb-1.5">{before}{#if index >= 0}<MarkerUnderline>{word}</MarkerUnderline>{/if}{after}</h1>
            {/if}
            {#if description}
                <p class="type-body-sm mb-7 text-muted-foreground">{description}</p>
            {/if}
            <div class="rounded-xl border border-border bg-card p-6">
                {@render children?.()}
            </div>
        </div>
    </main>

    <footer class="relative z-10 border-t border-border">
        <div class="mx-auto flex w-full max-w-[1200px] flex-wrap items-center gap-6 px-10 py-8 max-sm:px-5">
            <span class="text-[12.5px] text-muted-foreground">© 2026 Monoven Ltd · coresmith.io · ELv2 licensed</span>
            <MonovenMark class="ml-auto" />
        </div>
    </footer>

    <Toast />
</div>
