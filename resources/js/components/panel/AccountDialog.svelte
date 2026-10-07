<script lang="ts">
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { getInitials } from '@/lib/initials'
    import type { PanelProps } from '@/lib/panel'
    import { cn } from '@/lib/utils'
    import { page, router } from '@inertiajs/svelte'

    let { open = $bindable(false) }: { open?: boolean } = $props()

    const panel = $derived(page.props.panel as PanelProps | null)
    const user = $derived(page.props.auth.user)

    const description = $derived(
        panel?.team?.role
            ? `Signed in as ${user.email}, ${panel.team.role.toLowerCase()} of ${panel.team.name}.`
            : `Signed in as ${user.email}.`
    )

    function plural(count: number, one: string, many: string): string {
        return `${count} ${count === 1 ? one : many}`
    }

    let switching = $state<string | null>(null)

    function signOut() {
        open = false
        router.flushAll()
        router.post(route('logout'))
    }

    function switchTo(teamId: string) {
        switching = teamId
        router.put(route('current-team.update'), { team: teamId }, { onFinish: () => (switching = null) })
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Account</Dialog.Title>
            <Dialog.Description>{description}</Dialog.Description>
        </Dialog.Header>

        {#if panel && panel.teams.length > 0}
            <div
                class="-mx-1 mt-3 -mb-1 flex max-h-[min(24rem,50dvh)] flex-col gap-2 overflow-y-auto p-1"
                data-testid="team-switcher"
            >
                {#each panel.teams as team (team.id)}
                    {@const current = team.id === panel.team?.id}
                    {#snippet row()}
                        <span
                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground"
                        >
                            {getInitials(team.name)}
                        </span>
                        <span class="block min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium">{team.name}</span>
                            <span class="block text-[12px] text-muted-foreground">
                                {plural(team.servers, 'server', 'servers')} · {current
                                    ? plural(team.members, 'person', 'people')
                                    : (team.role ?? '').toLowerCase()}
                            </span>
                        </span>
                    {/snippet}
                    {#if current}
                        <div
                            class="option-selected flex items-center gap-3 rounded-md border px-3.5 py-3"
                            aria-current="true"
                        >
                            {@render row()}
                            <span class="text-[12px] font-medium text-primary-strong">current</span>
                        </div>
                    {:else}
                        <button
                            type="button"
                            class={cn(
                                'focus-ring flex w-full cursor-pointer items-center gap-3 rounded-md border border-border bg-transparent px-3.5 py-3 text-left text-foreground transition-colors duration-(--dur-fast) hover:bg-muted',
                                switching === team.id && 'opacity-60'
                            )}
                            aria-label="Switch to {team.name}"
                            disabled={switching !== null}
                            onclick={() => switchTo(team.id)}
                        >
                            {@render row()}
                            {#if switching === team.id}
                                <span class="text-[12px] font-medium text-muted-foreground">Switching…</span>
                            {/if}
                        </button>
                    {/if}
                {/each}
            </div>
        {/if}

        <div class="mt-4 flex flex-wrap justify-between gap-2">
            <Button variant="ghost" onclick={signOut} data-testid="sign-out">Sign out</Button>
            <div class="flex gap-2">
                <Button variant="ghost" href={route('profile.edit')} onclick={() => (open = false)}>Your account</Button
                >
                <Button variant="outline" href={route('settings.general')} onclick={() => (open = false)}
                    >Panel settings</Button
                >
            </div>
        </div>
    </Dialog.Content>
</Dialog.Root>
