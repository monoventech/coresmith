<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import ServerTabs from '@/components/ServerTabs.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Spinner } from '@/components/ui/spinner'
    import { Form } from '@inertiajs/svelte'

    let {
        server,
        can
    }: {
        server: { id: string; name: string; ssh_port: number; audit_logging_enabled: boolean }
        can: { update: boolean }
    } = $props()
</script>

<AppHead title="Edit {server.name}" />

<div class="px-6 py-8">
    <Heading title="Edit {server.name}" description="The IP address and SSH user are fixed once a server is added." />

    <ServerTabs serverId={server.id} canUpdate={can.update} />

    <Form method="put" action="/servers/{server.id}" class="max-w-xl space-y-6">
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" value={server.name} required />
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="ssh_port">SSH port</Label>
                <Input id="ssh_port" name="ssh_port" type="number" value={server.ssh_port} required />
                <InputError message={errors.ssh_port} />
            </div>

            <Label for="audit_logging_enabled" class="flex items-center gap-3">
                <Checkbox id="audit_logging_enabled" name="audit_logging_enabled" checked={server.audit_logging_enabled} />
                <span>Enable audit logging</span>
            </Label>

            <Button type="submit" disabled={processing}>
                {#if processing}<Spinner />{/if}
                Save changes
            </Button>
        {/snippet}
    </Form>
</div>
