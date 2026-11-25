<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { PageSection } from '@/lib/app-page'
    import { cn } from '@/lib/utils'

    let { sections }: { sections: PageSection[] } = $props()

    const PASSED = 120

    let active = $state<string | null>(null)
    let chosen: string | null = null

    $effect(() => {
        const scroller = document.getElementById('main')
        const ids = sections.map((section) => section.id)

        if (!scroller) {
            return
        }

        const update = () => {
            if (chosen !== null) {
                active = chosen

                return
            }

            const shown = ids
                .map((id) => document.getElementById(id))
                .filter((element): element is HTMLElement => element !== null && element.offsetParent !== null)
            const line = scroller.getBoundingClientRect().top + PASSED
            const bottom = scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 2
            const passed = shown.filter((element) => element.getBoundingClientRect().top <= line)

            active = (bottom ? shown.at(-1) : passed.at(-1))?.id ?? shown[0]?.id ?? null
        }

        let frame = 0
        const onScroll = () => {
            cancelAnimationFrame(frame)
            frame = requestAnimationFrame(update)
        }
        const release = () => (chosen = null)

        update()
        scroller.addEventListener('scroll', onScroll, { passive: true })
        scroller.addEventListener('wheel', release, { passive: true })
        scroller.addEventListener('touchstart', release, { passive: true })
        window.addEventListener('keydown', release)

        return () => {
            cancelAnimationFrame(frame)
            scroller.removeEventListener('scroll', onScroll)
            scroller.removeEventListener('wheel', release)
            scroller.removeEventListener('touchstart', release)
            window.removeEventListener('keydown', release)
        }
    })

    function jump(event: MouseEvent, id: string) {
        const target = document.getElementById(id)

        if (!target) {
            return
        }

        event.preventDefault()
        const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        chosen = id
        active = id
        target.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' })
        window.history.replaceState(window.history.state, '', `#${id}`)
    }
</script>

<nav
    aria-label="On this page"
    class="sticky top-0 z-10 -mx-1 mb-5 border-b border-border bg-background px-1 max-sm:static max-sm:-mx-4.5 max-sm:mb-3 max-sm:border-b-0 max-sm:bg-transparent max-sm:px-4.5"
    data-section-index
    data-testid="section-index"
>
    <ul class="m-0 flex list-none gap-1 overflow-x-auto p-0 max-sm:gap-2">
        {#each sections as section (section.id)}
            <li class={cn('shrink-0', section.phone === false && 'max-sm:hidden')}>
                <a
                    href="#{section.id}"
                    aria-current={active === section.id ? 'location' : undefined}
                    onclick={(event) => jump(event, section.id)}
                    class={cn(
                        'focus-ring inline-flex min-h-10 items-center rounded-md px-3 text-[13.5px] whitespace-nowrap text-muted-foreground no-underline transition-colors duration-(--dur-fast) hover:text-foreground',
                        'max-sm:min-h-11 max-sm:border max-sm:border-border max-sm:px-3.5',
                        active === section.id &&
                            'font-semibold text-foreground shadow-[inset_0_-2px_0_var(--foreground)] max-sm:border-foreground max-sm:bg-foreground max-sm:text-background max-sm:shadow-none'
                    )}>{section.label}</a
                >
            </li>
        {/each}
    </ul>
</nav>
