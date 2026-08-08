<script lang="ts">
    import { page } from '@inertiajs/svelte'
    import AppLogoIcon from '@/components/AppLogoIcon.svelte'

    const appName = $derived(page.props.appName ?? 'Coresmith')
</script>

<div class="flex items-center gap-2">
    <div class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
        <AppLogoIcon class="size-5" />
    </div>
    <span class="font-display text-base leading-none font-semibold tracking-[-0.01em]">{appName}</span>
</div>
