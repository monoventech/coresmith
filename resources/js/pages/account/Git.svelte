<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import GitHubRegistration from '@/components/settings/GitHubRegistration.svelte'
    import { Button } from '@/components/ui/button'
    import type { GitHubRegistration as Registration } from '@/lib/github'
    import { router } from '@inertiajs/svelte'

    let {
        team,
        can,
        connection,
        app,
        registration
    }: {
        team: { name: string }
        can: { connect: boolean }
        connection: { account: string; connected: boolean; manage_url: string | null } | null
        app: { name: string; url: string; install_href: string } | null
        registration: Registration | null
    } = $props()

    let removing = $state(false)

    function remove() {
        removing = true
        router.delete(route('account.git.destroy'), { onFinish: () => (removing = false) })
    }
</script>

<AppHead title="Your GitHub" />

<div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="own-github">
    <h3 class="type-h3 m-0 mb-1">Your own GitHub in {team.name}</h3>
    <p class="mb-4 text-[12.5px] text-muted-foreground">
        Repositories you connect here appear only in your own Add an app form. No one else in the team can list, read or
        add apps from them.
    </p>

    {#if connection}
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
            <p class="m-0 text-[13.5px]">
                Connected as <span class="font-mono text-[13px]">{connection.account}</span>{connection.connected
                    ? '.'
                    : ', but GitHub has suspended the install.'}
            </p>
            <div class="flex gap-2">
                {#if connection.manage_url}
                    <Button variant="outline" size="sm" href={connection.manage_url}>Choose repositories</Button>
                {/if}
                <Button variant="ghost" size="sm" disabled={removing} onclick={remove}>Remove</Button>
            </div>
        </div>
    {:else if app}
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
            <p class="m-0 text-[13.5px]">{app.name} is created. Install it on your GitHub account to finish.</p>
            <div class="flex gap-2">
                <Button size="sm" href={app.install_href}>Install on GitHub</Button>
                <Button variant="ghost" size="sm" disabled={removing} onclick={remove}>Start again</Button>
            </div>
        </div>
    {:else if registration}
        <GitHubRegistration {registration} compact personalOnly />
    {:else if !can.connect}
        <p class="m-0 border-t border-border pt-4 text-[13.5px] text-muted-foreground">
            Your role in {team.name} doesn't add apps, so there is nothing to connect here.
        </p>
    {/if}
</div>
