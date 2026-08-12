<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import ServerTabs from '@/components/ServerTabs.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'

    type Change = {
        id: number
        action: string | null
        port: number | null
        from: string | null
        at: string | null
    }

    let {
        server,
        changes = [],
        can
    }: {
        server: { id: string; name: string; ssh_port: number }
        changes: Change[]
        can: { manage: boolean }
    } = $props()

    const selectClass =
        'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'
</script>

<AppHead title="{server.name} firewall" />

<div class="px-6 py-8">
    <Heading title={server.name} description="Ports this server accepts traffic on." variant="small" />

    <ServerTabs serverId={server.id} canUpdate={can.manage} />

    <p class="mb-6 max-w-2xl text-sm leading-relaxed text-muted-foreground">
        Coresmith records the changes it applies. It does not read the live rule table off the server, so anything
        changed outside Coresmith will not appear here.
    </p>

    {#if can.manage}
        <Form method="post" action={route('servers.firewall.store', server.id)} class="mb-10 max-w-xl space-y-4">
            {#snippet children({ errors, processing })}
                <div class="flex flex-wrap items-end gap-3">
                    <div class="grid gap-2">
                        <Label for="action">Action</Label>
                        <select id="action" name="action" class={selectClass} required>
                            <option value="allow">Allow</option>
                            <option value="deny">Deny</option>
                        </select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="port">Port</Label>
                        <Input id="port" name="port" type="number" min="1" max="65535" required class="w-28" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="from_ip">From IP <span class="text-muted-foreground">(optional)</span></Label>
                        <Input id="from_ip" name="from_ip" placeholder="203.0.113.4" class="w-44" />
                    </div>

                    <Button type="submit" disabled={processing}>{processing ? 'Queueing…' : 'Apply'}</Button>
                </div>

                <InputError message={errors.port} />
                <InputError message={errors.from_ip} />
                <p class="text-xs text-muted-foreground">
                    Port {server.ssh_port} is how Coresmith reaches this server, so it cannot be denied here.
                </p>
            {/snippet}
        </Form>
    {/if}

    <h2 class="mb-4 text-xl font-semibold tracking-[-0.01em]">Changes Coresmith applied</h2>

    {#if changes.length === 0}
        <p class="rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
            Nothing yet. Rules you apply here will be listed with the time they were queued.
        </p>
    {:else}
        <ul class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
            {#each changes as change (change.id)}
                <li class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                    <span class="font-mono text-xs">
                        {change.action}
                        {change.port}{change.from ? ` from ${change.from}` : ''}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {change.at ? new Date(change.at).toLocaleString() : ''}
                    </span>
                </li>
            {/each}
        </ul>
    {/if}
</div>
