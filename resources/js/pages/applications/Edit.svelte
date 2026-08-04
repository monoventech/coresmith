<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Spinner } from '@/components/ui/spinner'
    import { Form } from '@inertiajs/svelte'

    let {
        server,
        application
    }: { server: { id: string; name: string }; application: Record<string, any> } = $props()
</script>

<AppHead title="Edit {application.name}" />

<div class="px-6 py-8">
    <Heading title="Edit {application.name}" description="The slug and system user are fixed once an application exists." />

    <Form method="put" action="/servers/{server.id}/applications/{application.id}" class="max-w-xl space-y-6">
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" value={application.name} required />
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="domain">Domain</Label>
                <Input id="domain" name="domain" value={application.domain ?? ''} />
                <InputError message={errors.domain} />
            </div>

            <input type="hidden" name="type" value={application.type} />
            <input type="hidden" name="app_size" value={application.app_size} />
            <input type="hidden" name="repository_branch" value={application.repository_branch ?? 'main'} />
            <input type="hidden" name="directory" value={application.directory ?? '/'} />
            <input type="hidden" name="web_directory" value={application.web_directory ?? '/public'} />

            <Button type="submit" disabled={processing}>
                {#if processing}<Spinner />{/if}
                Save changes
            </Button>
        {/snippet}
    </Form>
</div>
