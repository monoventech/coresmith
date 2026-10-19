<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import RuntimeVersionPicker from '@/components/applications/RuntimeVersionPicker.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import { pickerRuntime } from '@/lib/runtimes'
    import type { RuntimeChoices } from '@/lib/runtimes'

    let {
        server,
        runtimes
    }: { server: { id: string; name: string; href: string }; runtimes: Record<string, RuntimeChoices> } = $props()

    const FRAMEWORKS: Record<string, { value: string; label: string }[]> = {
        php: [
            { value: 'laravel', label: 'Laravel' },
            { value: 'symfony', label: 'Symfony' },
            { value: '', label: 'Plain PHP' }
        ],
        js: [
            { value: 'sveltekit', label: 'SvelteKit' },
            { value: 'nextjs', label: 'Next.js' },
            { value: 'nuxt', label: 'Nuxt' },
            { value: 'astro', label: 'Astro' },
            { value: '', label: 'API or other' }
        ],
        ruby: [
            { value: 'rails', label: 'Rails' },
            { value: 'sinatra', label: 'Sinatra' }
        ],
        static: [{ value: '', label: 'No framework' }]
    }

    const STEPS = [
        { name: 'Clone release', desc: 'fetched with a read-only deploy key' },
        { name: 'Install dependencies', desc: 'composer, bun or bundler' },
        { name: 'Build assets', desc: 'into a new timestamped directory' },
        { name: 'Run migrations', desc: 'against the new release' },
        { name: 'Health check', desc: 'before anything goes live' },
        { name: 'Symlink swap', desc: 'atomic, the old release kept for rollback' }
    ]

    let type = $state('php')
    let framework = $derived(FRAMEWORKS[type]?.[0]?.value ?? '')
    let jsRuntime = $state('bun')
    const picker = $derived(pickerRuntime(type, jsRuntime))
    let pickedSize = $state<string | null>(null)
    const size = $derived(pickedSize ?? (type === 'ruby' ? 'medium' : 'small'))
</script>

<AppHead title="Add an app" />

<BackLink href={server.href} label={server.name} />

<h1 class="type-h1 m-0 mb-1.5">Add an app</h1>
<p class="type-body-sm mb-7 text-muted-foreground">
    It runs on <span class="font-mono text-[13px]">{server.name}</span> as its own Linux user, with its own port block and
    env file.
</p>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card p-6">
        <Form method="post" action={route('servers.applications.store', server.id)}>
            {#snippet children({ errors, processing })}
                <input type="hidden" name="directory" value="/" />
                <div class="flex flex-col gap-4">
                    <div>
                        <Label for="name" class="mb-1.5">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            required
                            placeholder="atlas"
                            autocomplete="off"
                            aria-invalid={errors.name ? true : undefined}
                        />
                        <FieldError message={errors.name} />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label for="type" class="mb-1.5">Language</Label>
                            <NativeSelect
                                id="type"
                                name="type"
                                bind:value={type}
                                options={[
                                    { value: 'php', label: 'PHP' },
                                    { value: 'js', label: 'JavaScript' },
                                    { value: 'ruby', label: 'Ruby' },
                                    { value: 'static', label: 'Static site' }
                                ]}
                            />
                        </div>
                        <div>
                            <Label for="framework" class="mb-1.5">Framework</Label>
                            <NativeSelect
                                id="framework"
                                name="framework"
                                bind:value={framework}
                                options={FRAMEWORKS[type] ?? []}
                            />
                        </div>
                    </div>
                    {#if type === 'js'}
                        <div>
                            <Label for="js_runtime" class="mb-1.5">Runtime</Label>
                            <NativeSelect
                                id="js_runtime"
                                name="js_runtime"
                                bind:value={jsRuntime}
                                options={[
                                    { value: 'bun', label: 'Bun' },
                                    { value: 'node', label: 'Node' },
                                    { value: 'deno', label: 'Deno' }
                                ]}
                            />
                        </div>
                    {/if}
                    {#if picker && runtimes[picker]}
                        {#key picker}
                            <RuntimeVersionPicker runtime={runtimes[picker]} error={errors.runtime_version} />
                        {/key}
                    {/if}
                    <div>
                        <Label for="repository_url" class="mb-1.5">Repository</Label>
                        <Input
                            id="repository_url"
                            name="repository_url"
                            placeholder="git@github.com:you/atlas.git"
                            autocomplete="off"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.repository_url ? true : undefined}
                        />
                        <FieldError message={errors.repository_url} />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label for="repository_branch" class="mb-1.5">Branch</Label>
                            <Input
                                id="repository_branch"
                                name="repository_branch"
                                value="main"
                                required
                                class="font-mono text-[13px]"
                                aria-invalid={errors.repository_branch ? true : undefined}
                            />
                            <FieldError message={errors.repository_branch} />
                        </div>
                        <div>
                            <Label for="web_directory" class="mb-1.5">Web root</Label>
                            <Input
                                id="web_directory"
                                name="web_directory"
                                value="/public"
                                required
                                class="font-mono text-[13px]"
                                aria-invalid={errors.web_directory ? true : undefined}
                            />
                            <FieldError message={errors.web_directory} />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label for="domain" class="mb-1.5">Domain</Label>
                            <Input
                                id="domain"
                                name="domain"
                                placeholder="app.example.com"
                                autocomplete="off"
                                aria-invalid={errors.domain ? true : undefined}
                            />
                            <FieldError message={errors.domain} />
                        </div>
                        <div>
                            <Label for="app_size" class="mb-1.5">Size</Label>
                            <NativeSelect
                                id="app_size"
                                name="app_size"
                                bind:value={() => size, (value: string) => (pickedSize = value)}
                                options={[
                                    { value: 'small', label: 'Small, side projects' },
                                    { value: 'medium', label: 'Medium, production' },
                                    { value: 'large', label: 'Large, heavy traffic' }
                                ]}
                            />
                        </div>
                    </div>
                    <p class="-mt-1 text-[12px] text-muted-foreground">
                        Point the domain's A record at this server first. Caddy issues the certificate on the first
                        request.
                    </p>
                    <Button type="submit" class="w-full" disabled={processing}>Add the app</Button>
                </div>
            {/snippet}
        </Form>
    </div>

    <div class="rounded-xl border border-border bg-card p-6">
        <h3 class="type-h3 m-0 mb-1">What a deploy does</h3>
        <p class="mb-4 text-[12.5px] text-muted-foreground">
            The current release keeps serving traffic until the last step.
        </p>
        <ol class="m-0 flex list-none flex-col gap-0.5 p-0">
            {#each STEPS as step, index (step.name)}
                <li class="flex items-baseline gap-3 py-1.75">
                    <span class="w-4 flex-none font-mono text-[12px] text-primary">{index + 1}</span>
                    <span class="text-[13.5px]"
                        ><span class="font-medium">{step.name}</span><span class="text-muted-foreground"
                            >{`, ${step.desc}`}</span
                        ></span
                    >
                </li>
            {/each}
        </ol>
    </div>
</div>
