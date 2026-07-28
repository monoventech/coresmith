<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: route('dashboard')
            }
        ]
    }
</script>

<script lang="ts">
    import { page } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import Heading from '@/components/Heading.svelte'
    import BlueprintRack from '@/components/illustrations/BlueprintRack.svelte'
    import { Button } from '@/components/ui/button'

    const user = $derived(page.props.auth.user)
    const firstName = $derived(user.name.split(' ')[0])
</script>

<AppHead title="Dashboard" />

<div class="px-6 py-8">
    <Heading title="Welcome back, {firstName}" mark={firstName} description="Everything you have provisioned lives here." />

    <EmptyState
        title="No servers yet"
        description="Connect your first server and Coresmith will provision it, keep it patched, and deploy to it."
    >
        {#snippet illustration()}
            <BlueprintRack />
        {/snippet}
        {#snippet action()}
            <div class="space-y-2">
                <Button disabled>Connect a server</Button>
                <p class="text-xs text-muted-foreground">Provisioning arrives with the servers release.</p>
            </div>
        {/snippet}
    </EmptyState>
</div>
