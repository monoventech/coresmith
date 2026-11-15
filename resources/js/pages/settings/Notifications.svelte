<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import MonitorsCard from '@/components/settings/MonitorsCard.svelte'
    import type { Monitors } from '@/components/settings/MonitorsCard.svelte'
    import ToggleRow from '@/components/ToggleRow.svelte'

    type Toggle = { key: string; label: string; description: string; on: boolean; locked: boolean }

    let {
        email,
        toggles = [],
        monitors = null
    }: { email: string; toggles: Toggle[]; monitors?: Monitors | null } = $props()

    let busy = $state<Record<string, boolean>>({})

    function flip(toggle: Toggle, on: boolean) {
        busy = { ...busy, [toggle.key]: true }
        router.put(
            route('settings.notifications.update'),
            { key: toggle.key, on },
            { preserveScroll: true, onFinish: () => (busy = { ...busy, [toggle.key]: false }) }
        )
    }
</script>

<AppHead title="Notifications" />

<div class="flex flex-col gap-5">
    <div class="max-w-165 rounded-xl border border-border bg-card px-5.5 py-3" data-testid="notifications-card">
        {#each toggles as toggle (toggle.key)}
            <ToggleRow
                label={toggle.label}
                description={toggle.description}
                checked={toggle.on}
                busy={busy[toggle.key]}
                disabled={toggle.locked}
                onchange={(on) => flip(toggle, on)}
                bordered="bottom"
                testid="notification-{toggle.key}"
            />
        {/each}
        <p class="m-0 py-3 text-[12.5px] text-muted-foreground">
            Sent to {email}. Failures always notify, that one can't be turned off.
        </p>
    </div>

    {#if monitors}
        <MonitorsCard {monitors} />
    {/if}
</div>
