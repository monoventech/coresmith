<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Spinner } from '@/components/ui/spinner'
    import { Textarea } from '@/components/ui/textarea'
    import { Form, router } from '@inertiajs/svelte'

    let { keys = [] }: { keys: { id: string; name: string; fingerprint: string; created_at: string | null }[] } = $props()
</script>

<AppHead title="SSH keys" />

<div class="space-y-8">
    <Heading variant="small" title="SSH keys" description="Keys you use to reach your own servers. Coresmith uses its own key per server for provisioning." />

    {#if keys.length > 0}
        <ul class="divide-y divide-border rounded-xl border border-border">
            {#each keys as key (key.id)}
                <li class="flex items-center justify-between gap-4 p-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{key.name}</p>
                        <p class="truncate font-mono text-xs text-muted-foreground">{key.fingerprint}</p>
                    </div>
                    <Button variant="ghost" size="sm" onclick={() => router.delete(`/ssh-keys/${key.id}`)}>Remove</Button>
                </li>
            {/each}
        </ul>
    {/if}

    <Form method="post" action="/ssh-keys" class="max-w-xl space-y-4">
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required placeholder="Laptop" />
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="public_key">Public key</Label>
                <Textarea id="public_key" name="public_key" rows={4} required placeholder="ssh-ed25519 AAAA..." class="font-mono text-xs" />
                <InputError message={errors.public_key} />
            </div>

            <Button type="submit" disabled={processing}>
                {#if processing}<Spinner />{/if}
                Add key
            </Button>
        {/snippet}
    </Form>
</div>
