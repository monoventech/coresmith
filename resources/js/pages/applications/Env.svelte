<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Spinner } from '@/components/ui/spinner'
    import { Textarea } from '@/components/ui/textarea'
    import { Form } from '@inertiajs/svelte'

    let {
        server,
        application,
        env = ''
    }: { server: { id: string; name: string }; application: { id: string; name: string }; env: string } = $props()
</script>

<AppHead title="Environment · {application.name}" />

<div class="px-6 py-8">
    <Heading
        title="Environment"
        description="These values are encrypted at rest and written to a root-owned file on the server. The application user cannot read the file directly."
    />

    <Form method="put" action="/servers/{server.id}/applications/{application.id}/env" class="max-w-3xl space-y-6">
        {#snippet children({ errors, processing })}
            <Textarea name="env" rows={18} value={env} class="font-mono text-xs" placeholder="APP_ENV=production" />
            <InputError message={errors.env} />

            <div class="flex items-center gap-3">
                <Button type="submit" disabled={processing}>
                    {#if processing}<Spinner />{/if}
                    Save environment
                </Button>
                <p class="text-sm text-muted-foreground">Deploy afterwards to apply the change.</p>
            </div>
        {/snippet}
    </Form>
</div>
