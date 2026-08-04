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

<AppHead title="New application" />

<div class="px-6 py-8">
    <Heading title="New application" description="Deploying to {server.name}. Coresmith allocates the port block and system user for you." />

    <Form method="post" action="/servers/{server.id}/applications" class="max-w-xl space-y-6">
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required placeholder="My Svelte App" />
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="type">Type</Label>
                    <select id="type" name="type" class={selectClass} required>
                        <option value="php">PHP</option>
                        <option value="js">JavaScript</option>
                        <option value="ruby">Ruby</option>
                        <option value="static">Static</option>
                    </select>
                    <InputError message={errors.type} />
                </div>

                <div class="grid gap-2">
                    <Label for="app_size">Size</Label>
                    <select id="app_size" name="app_size" class={selectClass} required>
                        <option value="small">Small — side projects, staging</option>
                        <option value="medium">Medium — production traffic</option>
                        <option value="large">Large — high traffic</option>
                    </select>
                    <InputError message={errors.app_size} />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="domain">Domain</Label>
                <Input id="domain" name="domain" placeholder="app.example.com" />
                <InputError message={errors.domain} />
                <p class="text-xs text-muted-foreground">Point an A record at this server first. Caddy issues the certificate automatically.</p>
            </div>

            <div class="grid gap-2">
                <Label for="repository_url">Repository</Label>
                <Input id="repository_url" name="repository_url" placeholder="https://github.com/you/project.git" />
                <InputError message={errors.repository_url} />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="repository_branch">Branch</Label>
                    <Input id="repository_branch" name="repository_branch" value="main" required />
                    <InputError message={errors.repository_branch} />
                </div>

                <div class="grid gap-2">
                    <Label for="web_directory">Web root</Label>
                    <Input id="web_directory" name="web_directory" value="/public" required />
                    <InputError message={errors.web_directory} />
                </div>
            </div>

            <input type="hidden" name="directory" value="/" />

            <Button type="submit" disabled={processing}>
                {#if processing}<Spinner />{/if}
                Create application
            </Button>
        {/snippet}
    </Form>
</div>
