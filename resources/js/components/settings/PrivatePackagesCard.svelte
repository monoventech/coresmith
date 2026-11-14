<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import { packageFields } from '@/lib/packages'
    import type { PackageEntry, PackageSettings, PackageSourceValue } from '@/lib/packages'
    import { Form, router } from '@inertiajs/svelte'
    import { X } from '@lucide/svelte'

    let { packages }: { packages: PackageSettings } = $props()

    let open = $state(false)
    let editing = $state<PackageEntry | null>(null)
    let source = $state<PackageSourceValue>('composer')
    let removing = $state<PackageEntry | null>(null)
    let removeOpen = $state(false)
    let working = $state(false)

    const fields = $derived(packageFields[source])
    const count = $derived(packages.entries.length === 1 ? '1 credential' : `${packages.entries.length} credentials`)
    const sourceOptions = $derived(packages.sources.map((option) => ({ value: option.value, label: option.label })))
    const projectOptions = $derived([
        { value: '', label: 'Every app' },
        ...packages.projects.map((project) => ({ value: project.id, label: `Apps in ${project.name}` }))
    ])

    function openAdd() {
        editing = null
        source = 'composer'
        open = true
    }

    function openEdit(entry: PackageEntry) {
        editing = entry
        source = entry.source
        open = true
    }

    function askRemove(entry: PackageEntry) {
        removing = entry
        removeOpen = true
    }

    function remove() {
        if (!removing) {
            return
        }

        working = true
        router.delete(route('package-credentials.destroy', removing.id), {
            preserveScroll: true,
            onFinish: () => ((working = false), (removeOpen = false))
        })
    }
</script>

<div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="private-packages-card">
    <div class="px-5.5 pt-4 pb-3">
        <div class="flex flex-wrap items-center gap-2.5">
            <h3 class="type-h3 m-0">Private packages</h3>
            <span class="font-mono text-[12px] text-muted-foreground">{count}</span>
            {#if packages.can_manage && packages.entries.length > 0}
                <span class="ml-auto"><Button variant="ghost" size="sm" onclick={openAdd}>Add credentials</Button></span
                >
            {/if}
        </div>
        <p class="mt-1 mb-0 text-[12.5px] text-muted-foreground">
            Installs and builds use these to fetch private dependencies, like Laravel Nova or a GitHub Packages scope.
            The running app never sees them.
        </p>
    </div>

    {#if packages.entries.length === 0}
        <div class="flex flex-col items-start gap-1 border-t border-border px-5.5 py-5">
            <p class="m-0 text-[13.5px] font-medium">No private registries yet.</p>
            {#if packages.can_manage}
                <p class="m-0 text-[12.5px] text-muted-foreground">
                    Add one before an app installs from a private registry, so its first deploy works.
                </p>
                <Button class="mt-2" variant="ghost" size="sm" onclick={openAdd}>Add credentials</Button>
            {:else}
                <p class="m-0 text-[12.5px] text-muted-foreground">An owner or admin can add them.</p>
            {/if}
        </div>
    {:else}
        <ul class="m-0 max-h-80 list-none overflow-y-auto p-0">
            {#each packages.entries as entry (entry.id)}
                <li
                    class="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 border-t border-border px-5.5 py-1.5 text-[13.5px]"
                    data-testid="package-credential-row"
                >
                    <span class="font-medium">{entry.source_label}</span>
                    <span class="min-w-0 truncate font-mono text-[12.5px]" title={entry.target}>{entry.target}</span>
                    {#if entry.scope}
                        <span class="min-w-0 truncate font-mono text-[12px] text-muted-foreground">{entry.scope}</span>
                    {/if}
                    <span class="ml-auto flex flex-wrap items-center gap-2.5">
                        <Badge variant="outline">{entry.project ?? 'Every app'}</Badge>
                        {#if entry.hint}
                            <span class="font-mono text-[12px] text-muted-foreground">ends in {entry.hint}</span>
                        {/if}
                        {#if packages.can_manage}
                            <Button variant="ghost" size="sm" onclick={() => openEdit(entry)}>Edit</Button>
                            <button
                                type="button"
                                class="focus-ring inline-flex size-8 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted max-sm:size-11"
                                aria-label="Remove {entry.source_label} credentials for {entry.target}"
                                title="Remove"
                                onclick={() => askRemove(entry)}
                            >
                                <X class="size-4" />
                            </button>
                        {/if}
                    </span>
                </li>
            {/each}
        </ul>
    {/if}

    <p class="m-0 border-t border-border px-5.5 py-3 text-[12.5px] text-muted-foreground">
        Coresmith stores them encrypted and writes them to the server only while an install or build runs.
    </p>
</div>

<Dialog.Root bind:open>
    <Dialog.Content>
        {#key editing?.id ?? 'new'}
            <Form
                method={editing ? 'put' : 'post'}
                action={editing ? route('package-credentials.update', editing.id) : route('package-credentials.store')}
                options={{ preserveScroll: true }}
                onSuccess={() => (open = false)}
            >
                {#snippet children({ errors, processing })}
                    <Dialog.Header>
                        <Dialog.Title
                            >{editing ? `Edit credentials for ${editing.target}` : 'Add credentials'}</Dialog.Title
                        >
                        <Dialog.Description>
                            {editing
                                ? 'Leave the secret empty to keep the current one.'
                                : 'Coresmith encrypts the secret and never shows it again.'}
                        </Dialog.Description>
                    </Dialog.Header>
                    <div class="mt-4 flex flex-col gap-3">
                        {#if editing}
                            <input type="hidden" name="source" value={editing.source} />
                        {:else}
                            <div>
                                <Label for="package-source" class="mb-1.5">Packages from</Label>
                                <NativeSelect
                                    id="package-source"
                                    name="source"
                                    options={sourceOptions}
                                    value={source}
                                    onchange={(event) =>
                                        (source = (event.currentTarget as HTMLSelectElement)
                                            .value as PackageSourceValue)}
                                />
                                <FieldError message={errors.source} />
                            </div>
                        {/if}
                        <div>
                            <Label for="package-target" class="mb-1.5">{fields.target}</Label>
                            <Input
                                id="package-target"
                                name="target"
                                required
                                autocomplete="off"
                                spellcheck="false"
                                placeholder={fields.placeholder}
                                value={editing?.target ?? ''}
                                aria-invalid={errors.target ? true : undefined}
                            />
                            {#if fields.targetHint}
                                <p class="mt-1.5 mb-0 text-[12px] text-muted-foreground">{fields.targetHint}</p>
                            {/if}
                            <FieldError message={errors.target} />
                        </div>
                        {#if fields.scopes}
                            <div>
                                <Label for="package-scope" class="mb-1.5">Scopes, optional</Label>
                                <Input
                                    id="package-scope"
                                    name="scope"
                                    autocomplete="off"
                                    spellcheck="false"
                                    placeholder="@acme"
                                    value={editing?.scope ?? ''}
                                    aria-invalid={errors.scope ? true : undefined}
                                />
                                <p class="mt-1.5 mb-0 text-[12px] text-muted-foreground">
                                    Packages under these scopes install from this registry. Separate several with
                                    commas.
                                </p>
                                <FieldError message={errors.scope} />
                            </div>
                        {/if}
                        {#if fields.username}
                            <div>
                                <Label for="package-username" class="mb-1.5"
                                    >{fields.username === 'optional' ? 'Username, optional' : 'Username'}</Label
                                >
                                <Input
                                    id="package-username"
                                    name="username"
                                    required={fields.username === 'required'}
                                    autocomplete="off"
                                    spellcheck="false"
                                    value={editing?.username ?? ''}
                                    aria-invalid={errors.username ? true : undefined}
                                />
                                {#if fields.usernameHint}
                                    <p class="mt-1.5 mb-0 text-[12px] text-muted-foreground">{fields.usernameHint}</p>
                                {/if}
                                <FieldError message={errors.username} />
                            </div>
                        {/if}
                        <div>
                            <Label for="package-secret" class="mb-1.5">{fields.secret}</Label>
                            <Input
                                id="package-secret"
                                name="secret"
                                type="password"
                                required={!editing}
                                autocomplete="new-password"
                                placeholder={editing?.hint ? `Ends in ${editing.hint}` : undefined}
                                aria-invalid={errors.secret ? true : undefined}
                            />
                            <FieldError message={errors.secret} />
                        </div>
                        {#if packages.projects.length > 0}
                            <div>
                                <Label for="package-project" class="mb-1.5">Used by</Label>
                                <NativeSelect
                                    id="package-project"
                                    name="project_id"
                                    options={projectOptions}
                                    value={editing?.project_id ?? ''}
                                />
                                <FieldError message={errors.project_id} />
                            </div>
                        {/if}
                    </div>
                    <Dialog.Footer>
                        <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>{editing ? 'Save' : 'Add credentials'}</Button>
                    </Dialog.Footer>
                {/snippet}
            </Form>
        {/key}
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={removeOpen}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Remove the credentials for {removing?.target}?</Dialog.Title>
            <Dialog.Description
                >The next deploy installs without them. The token stays valid until you revoke it where it was issued.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (removeOpen = false)}>Keep them</Button>
            <Button variant="destructive" disabled={working} onclick={remove}>Remove</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
