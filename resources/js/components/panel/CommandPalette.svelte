<script lang="ts">
    import type { PaletteCommand } from '@/lib/panel'
    import { trackedPointer } from '@/lib/pointer'
    import { themeState } from '@/lib/theme.svelte'
    import { cn } from '@/lib/utils'
    import { page, router } from '@inertiajs/svelte'
    import {
        CreditCard,
        Folder,
        KeyRound,
        Layers,
        Plus,
        Rocket,
        Search,
        Server,
        SunMoon,
        TriangleAlert,
        Undo2
    } from '@lucide/svelte'
    import { tick } from 'svelte'
    import { fade } from 'svelte/transition'

    let { open = $bindable(false) }: { open?: boolean } = $props()

    const theme = themeState()

    const icons = {
        rocket: Rocket,
        server: Server,
        'triangle-alert': TriangleAlert,
        plus: Plus,
        'undo-2': Undo2,
        'sun-moon': SunMoon,
        'key-round': KeyRound,
        'credit-card': CreditCard,
        layers: Layers,
        folder: Folder
    } as const

    let query = $state('')
    let highlighted = $state(0)
    let loading = $state(false)
    let input = $state<HTMLInputElement | null>(null)
    let returnFocus: HTMLElement | null = null

    const pointer = trackedPointer()

    const commands = $derived((page.props.palette as PaletteCommand[] | undefined) ?? [])
    const filtered = $derived(
        commands.filter((command) => command.label.toLowerCase().includes(query.trim().toLowerCase()))
    )

    $effect(() => {
        if (!open) {
            return
        }

        returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
        query = ''
        highlighted = 0

        void tick().then(() => input?.focus())

        loading = true
        router.reload({ only: ['palette'], onFinish: () => (loading = false) })

        return () => returnFocus?.focus()
    })

    function close() {
        open = false
    }

    function run(command: PaletteCommand) {
        close()

        if (command.action === 'theme') {
            theme.updateAppearance(theme.resolvedAppearance() === 'dark' ? 'light' : 'dark')

            return
        }

        if (command.href) {
            router.visit(command.href)
        }
    }

    function hover(event: PointerEvent, index: number) {
        if (pointer.moved(event)) {
            highlighted = index
        }
    }

    function onKeydown(event: KeyboardEvent) {
        const count = filtered.length

        if (event.key === 'ArrowDown') {
            event.preventDefault()
            highlighted = count ? (highlighted + 1) % count : 0
        } else if (event.key === 'ArrowUp') {
            event.preventDefault()
            highlighted = count ? (highlighted - 1 + count) % count : 0
        } else if (event.key === 'Enter') {
            const command = filtered[highlighted]

            if (command) {
                event.preventDefault()
                run(command)
            }
        } else if (event.key === 'Escape') {
            event.preventDefault()
            close()
        }
    }
</script>

{#if open}
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div
        class="panel fixed inset-0 z-55 flex items-start justify-center bg-scrim px-6 pt-24 pb-6"
        onclick={close}
        transition:fade={{ duration: 200 }}
    >
        <div
            class="w-full max-w-130 overflow-hidden rounded-xl border border-border bg-popover text-popover-foreground shadow-(--shadow-lg)"
            role="dialog"
            aria-modal="true"
            aria-label="Command palette"
            tabindex="-1"
            onclick={(event) => event.stopPropagation()}
        >
            <div class="flex items-center gap-2.5 border-b border-border px-4.5 py-3.5">
                <Search class="size-4 text-muted-foreground" />
                <input
                    bind:this={input}
                    bind:value={query}
                    oninput={() => (highlighted = 0)}
                    onkeydown={onKeydown}
                    placeholder="Type a command or search…"
                    class="flex-1 border-none bg-transparent font-sans text-[14px] text-foreground outline-none placeholder:text-muted-foreground"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="palette-results"
                    aria-activedescendant={filtered[highlighted] ? `palette-option-${highlighted}` : undefined}
                    aria-label="Search commands"
                    autocomplete="off"
                    spellcheck="false"
                />
                <span class="rounded-sm border border-border px-1.25 py-px font-mono text-[11px] text-muted-foreground"
                    >esc</span
                >
            </div>

            <div id="palette-results" class="max-h-80 overflow-y-auto p-1.5" role="listbox" aria-label="Commands">
                {#each filtered as command, index (command.label)}
                    {@const Icon = icons[command.icon as keyof typeof icons] ?? Layers}
                    <button
                        id="palette-option-{index}"
                        type="button"
                        role="option"
                        tabindex="-1"
                        aria-selected={index === highlighted}
                        class={cn(
                            'flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-md px-3 text-left font-sans text-[13.5px] text-foreground transition-colors duration-(--dur-fast) hover:bg-muted',
                            index === highlighted && 'bg-muted'
                        )}
                        onpointerenter={pointer.enter}
                        onpointermove={(event) => hover(event, index)}
                        onclick={() => run(command)}
                    >
                        <Icon class="size-4 text-muted-foreground" />
                        <span class="flex-1 font-medium">{command.label}</span>
                        <span class="text-[12px] text-muted-foreground">{command.hint}</span>
                    </button>
                {:else}
                    <p class="px-3 py-3 text-[13px] text-muted-foreground">
                        {loading && commands.length === 0 ? 'Gathering commands…' : `Nothing matches “${query}”.`}
                    </p>
                {/each}
            </div>
        </div>
    </div>
{/if}
