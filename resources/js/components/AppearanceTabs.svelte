<script lang="ts">
    import Monitor from '@lucide/svelte/icons/monitor'
    import Moon from '@lucide/svelte/icons/moon'
    import Sun from '@lucide/svelte/icons/sun'
    import type { Component, SvelteComponent } from 'svelte'
    import { themeState } from '@/lib/theme.svelte'
    import { cn } from '@/lib/utils'
    import type { Appearance } from '@/types'

    const { appearance, updateAppearance } = themeState()

    type IconComponent = Component<{ class?: string }> | (new (...args: any[]) => SvelteComponent<{ class?: string }>)

    const tabs: { value: Appearance; Icon: IconComponent; label: string }[] = [
        { value: 'light', Icon: Sun, label: 'Light' },
        { value: 'dark', Icon: Moon, label: 'Dark' },
        { value: 'system', Icon: Monitor, label: 'System' }
    ]

    function handleAppearanceChange(value: Appearance) {
        updateAppearance(value)
    }
</script>

<div class="inline-flex gap-1 rounded-md bg-muted p-1" role="group" aria-label="Appearance">
    {#each tabs as { value, Icon, label } (value)}
        <button
            type="button"
            onclick={() => handleAppearanceChange(value)}
            aria-pressed={appearance.value === value}
            class={cn(
                'flex items-center rounded-md px-3.5 py-1.5 text-sm font-medium transition-colors outline-none',
                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                appearance.value === value
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-background/60 hover:text-foreground'
            )}
        >
            <Icon class="-ml-1 size-4" />
            <span class="ml-1.5">{label}</span>
        </button>
    {/each}
</div>
