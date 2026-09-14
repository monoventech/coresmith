<script lang="ts">
    import { page } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'
    import PageTabs from '@/components/PageTabs.svelte'
    import type { PanelProps } from '@/lib/panel'
    import { appPath } from '@/lib/panel'

    let { children }: { children?: Snippet } = $props()

    const path = $derived(appPath(page.url, (page.props.panel as PanelProps | null)?.base))

    const tabs = $derived([
        { label: 'General', href: route('settings.general'), current: path === '/settings' },
        { label: 'Team', href: route('teams.index'), current: path.startsWith('/settings/team') || path.startsWith('/teams') },
        { label: 'Git', href: route('source-providers.index'), current: path.startsWith('/settings/git') },
        { label: 'Notifications', href: route('settings.notifications'), current: path.startsWith('/settings/notifications') },
        { label: 'API tokens', href: route('api-tokens.index'), current: path.startsWith('/settings/api-tokens') },
        { label: 'Billing', href: route('settings.billing'), current: path.startsWith('/settings/billing') }
    ])

    const description = $derived.by(() => {
        if (path.startsWith('/settings/git')) {
            return 'GitHub App with fine-grained, per-repository permissions, not OAuth.'
        }

        if (path.startsWith('/settings/billing')) {
            return 'Coresmith Cloud is billed per connected server. Self-hosting is free under ELv2, every feature included.'
        }

        return 'Panel, team and notification preferences for this Coresmith installation.'
    })
</script>

<h1 class="type-h1 m-0">Settings</h1>
<p class="type-body-sm mt-1.5 text-muted-foreground">{description}</p>
<div class="mt-[22px] mb-9">
    <PageTabs {tabs} label="Settings" />
</div>
{@render children?.()}
