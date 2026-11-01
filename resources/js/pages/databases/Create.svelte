<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'

    let { server }: { server: { id: string; name: string; href: string } } = $props()
</script>

<AppHead title="Create a database" />

<BackLink href={server.href} label={server.name} />

<h1 class="type-h1 m-0 mb-1.5">Create a database</h1>
<p class="type-body-sm mb-7 text-muted-foreground">
    On <span class="font-mono text-[13px]">{server.name}</span>, listening on localhost only, with a user of its own.
</p>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card p-6">
        <Form method="post" action={route('servers.databases.store', server.id)}>
            {#snippet children({ errors, processing })}
                <div class="flex flex-col gap-4">
                    <div>
                        <Label for="type" class="mb-1.5">Engine</Label>
                        <NativeSelect id="type" name="type" value="postgresql" options={[{ value: 'postgresql', label: 'PostgreSQL' }, { value: 'mysql', label: 'MySQL' }, { value: 'redis', label: 'Redis' }]} />
                        <FieldError message={errors.type} />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label for="name" class="mb-1.5">Database name</Label>
                            <Input id="name" name="name" required placeholder="shop_production" class="font-mono text-[13px]" aria-invalid={errors.name ? true : undefined} />
                            <FieldError message={errors.name} />
                        </div>
                        <div>
                            <Label for="db_user" class="mb-1.5">User</Label>
                            <Input id="db_user" name="db_user" required placeholder="shop_app" class="font-mono text-[13px]" aria-invalid={errors.db_user ? true : undefined} />
                            <FieldError message={errors.db_user} />
                        </div>
                    </div>
                    <div>
                        <Label for="privilege_level" class="mb-1.5">Privileges</Label>
                        <NativeSelect
                            id="privilege_level"
                            name="privilege_level"
                            value="standard"
                            options={[
                                { value: 'standard', label: 'Standard, suits almost every app' },
                                { value: 'extended', label: 'Extended, adds views, routines and events' },
                                { value: 'full', label: 'Full, for apps that manage their own schema' }
                            ]}
                        />
                    </div>
                    <Button type="submit" class="w-full" disabled={processing}>Create the database</Button>
                </div>
            {/snippet}
        </Form>
    </div>

    <div class="rounded-xl border border-border bg-card p-6">
        <h3 class="type-h3 m-0 mb-1">How it's isolated</h3>
        <p class="mb-4 text-[12.5px] text-muted-foreground">The same boundaries every app gets.</p>
        <ol class="m-0 flex list-none flex-col gap-0.5 p-0">
            {#each [['Localhost only', 'never bound to a public interface'], ['Its own user', 'no shared credentials between apps'], ['Least privilege', 'standard grants unless you choose more'], ['Encrypted at rest', 'the password is vaulted after it is shown once']] as [name, desc], index (name)}
                <li class="flex items-baseline gap-3 py-1.75">
                    <span class="w-4 flex-none font-mono text-[12px] text-primary-strong">{index + 1}</span>
                    <span class="text-[13.5px]"><span class="font-medium">{name}</span><span class="text-muted-foreground">{`, ${desc}`}</span></span>
                </li>
            {/each}
        </ol>
    </div>
</div>
