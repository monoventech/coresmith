<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Spinner } from '@/components/ui/spinner'
    import { Form } from '@inertiajs/svelte'

    let { server }: { server: { id: string; name: string } } = $props()

    const selectClass =
        'h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'
</script>

<AppHead title="New database" />

<div class="px-6 py-8">
    <Heading title="New database" description="On {server.name}. The password is generated for you and stored encrypted." />

    <Form method="post" action="/servers/{server.id}/databases" class="max-w-xl space-y-6">
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="type">Engine</Label>
                <select id="type" name="type" class={selectClass} required>
                    <option value="mysql">MySQL</option>
                    <option value="postgresql">PostgreSQL</option>
                    <option value="redis">Redis</option>
                </select>
                <InputError message={errors.type} />
            </div>

            <div class="grid gap-2">
                <Label for="name">Database name</Label>
                <Input id="name" name="name" required placeholder="shop_production" class="font-mono text-xs" />
                <InputError message={errors.name} />
                <p class="text-xs text-muted-foreground">Letters, digits and underscores only — this becomes a SQL identifier.</p>
            </div>

            <div class="grid gap-2">
                <Label for="db_user">Database user</Label>
                <Input id="db_user" name="db_user" required placeholder="shop_user" class="font-mono text-xs" />
                <InputError message={errors.db_user} />
            </div>

            <div class="grid gap-2">
                <Label for="privilege_level">Privileges</Label>
                <select id="privilege_level" name="privilege_level" class={selectClass} required>
                    <option value="standard">Standard — suits almost every app</option>
                    <option value="extended">Extended — views, routines, events</option>
                    <option value="full">Full — legacy apps that manage their own schema</option>
                </select>
                <InputError message={errors.privilege_level} />
                <p class="text-xs text-muted-foreground">
                    No level grants administrative rights. The user can only ever touch this one database.
                </p>
            </div>

            <Button type="submit" disabled={processing}>
                {#if processing}<Spinner />{/if}
                Create database
            </Button>
        {/snippet}
    </Form>
</div>
