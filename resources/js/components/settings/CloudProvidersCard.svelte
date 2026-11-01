<script lang="ts">
    import CloudConnectFields from '@/components/cloud/CloudConnectFields.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { CloudAccountRow, CloudAccounts } from '@/lib/cloud'
    import { Form, router } from '@inertiajs/svelte'
    import { X } from '@lucide/svelte'

    let { cloud }: { cloud: CloudAccounts } = $props()

    let adding = $state(false)
    let chosen = $state('')
    let name = $state('')
    let named = $state(false)
    let replacing = $state<CloudAccountRow | null>(null)
    let replaceOpen = $state(false)
    let removing = $state<CloudAccountRow | null>(null)
    let removeOpen = $state(false)
    let working = $state(false)

    const type = $derived(cloud.types.find((option) => option.value === chosen) ?? cloud.types[0])
    const replaceType = $derived(cloud.types.find((option) => option.value === replacing?.type))
    const options = $derived(cloud.types.map((option) => ({ value: option.value, label: option.label })))
    const count = $derived(cloud.accounts.length === 1 ? '1 account' : `${cloud.accounts.length} accounts`)

    function openAdd() {
        chosen = cloud.types[0]?.value ?? ''
        name = cloud.types[0]?.label ?? ''
        named = false
        adding = true
    }

    function choose(value: string) {
        chosen = value

        if (!named) {
            name = cloud.types.find((option) => option.value === value)?.label ?? name
        }
    }

    function askReplace(account: CloudAccountRow) {
        replacing = account
        replaceOpen = true
    }

    function askRemove(account: CloudAccountRow) {
        removing = account
        removeOpen = true
    }

    function remove() {
        if (!removing) {
            return
        }

        working = true
        router.delete(route('cloud-providers.destroy', removing.id), {
            preserveScroll: true,
            onFinish: () => ((working = false), (removeOpen = false))
        })
    }
</script>

<div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="cloud-providers-card">
    <div class="px-5.5 pt-4 pb-3">
        <div class="flex flex-wrap items-center gap-2.5">
            <h3 class="type-h3 m-0">Cloud providers</h3>
            <span class="font-mono text-[12px] text-muted-foreground">{count}</span>
            {#if cloud.can_manage && cloud.accounts.length > 0}
                <span class="ml-auto"><Button variant="ghost" size="sm" onclick={openAdd}>Add a provider</Button></span>
            {/if}
        </div>
        <p class="mt-1 mb-0 text-[12.5px] text-muted-foreground">
            Coresmith uses these tokens to create, resize, reboot and destroy this team's servers. They're stored
            encrypted and never shown again.
        </p>
    </div>

    {#if cloud.accounts.length === 0}
        <div class="flex flex-col items-start gap-1 border-t border-border px-5.5 py-5">
            <p class="m-0 text-[13.5px] font-medium">No provider connected yet.</p>
            {#if cloud.can_manage}
                <p class="m-0 text-[12.5px] text-muted-foreground">Connect one to create servers from this panel.</p>
                <Button class="mt-2" variant="ghost" size="sm" onclick={openAdd}>Add a provider</Button>
            {:else}
                <p class="m-0 text-[12.5px] text-muted-foreground">An owner or admin can connect one.</p>
            {/if}
        </div>
    {:else}
        <ul class="m-0 max-h-72 list-none overflow-y-auto p-0">
            {#each cloud.accounts as account (account.id)}
                <li
                    class="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 border-t border-border px-5.5 py-1.5 text-[13.5px]"
                    data-testid="cloud-account-row"
                >
                    <span class="font-medium">{account.provider}</span>
                    <span class="min-w-0 truncate font-mono text-[12.5px]">{account.name}</span>
                    {#if account.label}
                        <span class="min-w-0 truncate font-mono text-[12px] text-muted-foreground">{account.label}</span
                        >
                    {/if}
                    <span class="ml-auto flex flex-wrap items-center gap-2.5">
                        {#if account.refused}
                            <Badge variant="destructive" title="Refused on {account.refused_on}">Token refused</Badge>
                        {:else}
                            <Badge variant="success">Connected</Badge>
                        {/if}
                        <span class="text-[12.5px] text-muted-foreground"
                            >{account.servers === 1 ? '1 server' : `${account.servers} servers`}</span
                        >
                        {#if cloud.can_manage}
                            <Button variant="ghost" size="sm" onclick={() => askReplace(account)}>Replace token</Button>
                            <button
                                type="button"
                                class="focus-ring inline-flex size-8 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted"
                                aria-label="Remove {account.name}"
                                title="Remove {account.name}"
                                onclick={() => askRemove(account)}
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
        A provider token reaches everything its project or account can. Use a token limited to one project where the
        provider offers it.
    </p>
</div>

<Dialog.Root bind:open={adding}>
    <Dialog.Content>
        <Form
            method="post"
            action={route('cloud-providers.store')}
            options={{ preserveScroll: true }}
            onSuccess={() => (adding = false)}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Add a provider</Dialog.Title>
                    <Dialog.Description
                        >Coresmith checks the token with the provider before saving it.</Dialog.Description
                    >
                </Dialog.Header>
                <input type="hidden" name="return_to" value="settings" />
                <div class="mt-4 flex flex-col gap-3">
                    <div>
                        <Label for="cloud-type" class="mb-1.5">Provider</Label>
                        <NativeSelect
                            id="cloud-type"
                            name="type"
                            {options}
                            value={chosen}
                            onchange={(event) => choose((event.currentTarget as HTMLSelectElement).value)}
                        />
                        <FieldError message={errors.type} />
                    </div>
                    <div>
                        <Label for="cloud-name" class="mb-1.5">Name</Label>
                        <Input
                            id="cloud-name"
                            name="name"
                            required
                            bind:value={name}
                            oninput={() => (named = true)}
                            aria-invalid={errors.name ? true : undefined}
                        />
                        <FieldError message={errors.name} />
                    </div>
                    {#if type}
                        <CloudConnectFields {type} error={errors.api_token} id="cloud-add-token" />
                    {/if}
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (adding = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>Connect {type?.label}</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={replaceOpen}>
    <Dialog.Content>
        {#if replacing && replaceType}
            {@const account = replacing}
            {@const accountType = replaceType}
            <Form
                method="put"
                action={route('cloud-providers.update', account.id)}
                options={{ preserveScroll: true }}
                onSuccess={() => (replaceOpen = false)}
            >
                {#snippet children({ errors, processing })}
                    <Dialog.Header>
                        <Dialog.Title>Replace the token for {account.name}</Dialog.Title>
                        <Dialog.Description
                            >Coresmith checks the new token before it replaces the old one.</Dialog.Description
                        >
                    </Dialog.Header>
                    <div class="mt-4">
                        <CloudConnectFields type={accountType} error={errors.api_token} id="cloud-replace-token" />
                    </div>
                    <Dialog.Footer>
                        <Button variant="ghost" type="button" onclick={() => (replaceOpen = false)}
                            >Keep the current token</Button
                        >
                        <Button type="submit" disabled={processing}>Replace token</Button>
                    </Dialog.Footer>
                {/snippet}
            </Form>
        {/if}
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={removeOpen}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Remove {removing?.name}?</Dialog.Title>
            <Dialog.Description
                >Coresmith forgets the token. It stays valid at {removing?.provider} until you revoke it there.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (removeOpen = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={remove}>Remove account</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
