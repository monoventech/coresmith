<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Spinner } from '@/components/ui/spinner'
    import { Form } from '@inertiajs/svelte'

    let { teams = [] }: { teams: { id: string; name: string }[] } = $props()
</script>

<AppHead title="Add a server" />

<div class="px-6 py-8">
    <Heading title="Add a server" description="Coresmith generates a key pair for this server. You add the public key to it, then test the connection." />

    <Form method="post" action="/servers" class="max-w-xl space-y-6">
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="team_id">Team</Label>
                <select
                    id="team_id"
                    name="team_id"
                    required
                    class="h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                >
                    {#each teams as team (team.id)}
                        <option value={team.id}>{team.name}</option>
                    {/each}
                </select>
                <InputError message={errors.team_id} />
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required placeholder="web-1" />
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="ip_address">IP address</Label>
                <Input id="ip_address" name="ip_address" required placeholder="203.0.113.10" />
                <InputError message={errors.ip_address} />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="ssh_port">SSH port</Label>
                    <Input id="ssh_port" name="ssh_port" type="number" value="22" required />
                    <InputError message={errors.ssh_port} />
                    <p class="text-xs text-muted-foreground">Coresmith opens this port in the firewall, so it must be the one your server really uses.</p>
                </div>

                <div class="grid gap-2">
                    <Label for="ssh_user">SSH user</Label>
                    <Input id="ssh_user" name="ssh_user" value="root" required />
                    <InputError message={errors.ssh_user} />
                </div>
            </div>

            <Button type="submit" disabled={processing}>
                {#if processing}<Spinner />{/if}
                Add server
            </Button>
        {/snippet}
    </Form>
</div>
