<script module lang="ts">
    export type SingleSignOnConnection = {
        issuer: string
        client_id: string
        email_domain: string
        verified: boolean
        enabled: boolean
        jit_provisioning: boolean
        record: { type: string; name: string; value: string }
    }

    export type SingleSignOn = {
        connection: SingleSignOnConnection | null
        redirect_uris: string[]
        can_manage: boolean
    }
</script>

<script lang="ts">
    import { Form, router } from '@inertiajs/svelte'
    import FieldError from '@/components/FieldError.svelte'
    import ToggleRow from '@/components/ToggleRow.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'

    let { sso }: { sso: SingleSignOn } = $props()

    let editing = $state(false)
    let removing = $state(false)
    let checking = $state(false)
    let switching = $state(false)
    let provisioning = $state(false)
    let working = $state(false)
    let copied = $state<string | null>(null)

    const connection = $derived(sso.connection)

    async function copy(value: string) {
        try {
            await navigator.clipboard.writeText(value)
            copied = value
            setTimeout(() => (copied = null), 2000)
        } catch {
            copied = null
        }
    }

    function check() {
        checking = true
        router.post(route('settings.sso.verify'), {}, { preserveScroll: true, onFinish: () => (checking = false) })
    }

    function setEnabled(enabled: boolean) {
        switching = true
        router.put(route('settings.sso.enabled'), { enabled }, { preserveScroll: true, onFinish: () => (switching = false) })
    }

    function setProvisioning(on: boolean) {
        provisioning = true
        router.put(route('settings.sso.provisioning'), { jit_provisioning: on }, { preserveScroll: true, onFinish: () => (provisioning = false) })
    }

    function remove() {
        working = true
        router.delete(route('settings.sso.destroy'), { preserveScroll: true, onFinish: () => ((working = false), (removing = false)) })
    }
</script>

<div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="sso-card">
    <div class="mb-1 flex flex-wrap items-center gap-2.5">
        <h3 class="type-h3 m-0">Single sign-on</h3>
        {#if connection?.enabled}
            <Badge variant="success" dot>On</Badge>
        {:else if connection?.verified}
            <Badge variant="outline">Off</Badge>
        {:else if connection}
            <Badge variant="outline">Waiting for DNS</Badge>
        {/if}
        {#if connection && sso.can_manage}
            <span class="ml-auto"><Button variant="ghost" size="sm" onclick={() => (editing = true)}>Edit</Button></span>
        {/if}
    </div>

    {#if connection}
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            People at <span class="font-mono text-[12px]">{connection.email_domain}</span> sign in through your identity provider with OpenID Connect.
        </p>
    {:else}
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Let people sign in through your identity provider, like Okta, Microsoft Entra ID or Google Workspace, with OpenID Connect.
        </p>
    {/if}

    <div class="grid grid-cols-2 gap-x-8 gap-y-4 max-[900px]:grid-cols-1">
        <div class="flex min-w-0 flex-col">
            {#if connection}
                <dl class="m-0 flex flex-col" data-testid="sso-details">
                    <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
                        <dt class="w-28 shrink-0 text-[12.5px] text-muted-foreground">Issuer</dt>
                        <dd class="m-0 min-w-0 flex-1 truncate font-mono text-[12.5px]" title={connection.issuer}>{connection.issuer}</dd>
                    </div>
                    <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
                        <dt class="w-28 shrink-0 text-[12.5px] text-muted-foreground">Client ID</dt>
                        <dd class="m-0 min-w-0 flex-1 truncate font-mono text-[12.5px]" title={connection.client_id}>{connection.client_id}</dd>
                    </div>
                    <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
                        <dt class="w-28 shrink-0 text-[12.5px] text-muted-foreground">Client secret</dt>
                        <dd class="m-0 min-w-0 flex-1 text-[12.5px] text-muted-foreground">Saved, never shown</dd>
                    </div>
                    <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
                        <dt class="w-28 shrink-0 text-[12.5px] text-muted-foreground">Email domain</dt>
                        <dd class="m-0 min-w-0 flex-1 truncate font-mono text-[12.5px]">{connection.email_domain}</dd>
                    </div>
                </dl>

                {#if !connection.verified}
                    <p class="mt-3 mb-2 text-[12.5px] text-muted-foreground">
                        Add this record at your DNS provider to prove you own <span class="font-mono text-[12px]">{connection.email_domain}</span>, then check it.
                    </p>
                    <div class="grid grid-cols-[52px_1fr_auto] items-center gap-2.5 border-t border-border py-2" data-testid="sso-dns-record">
                        <span class="font-mono text-[11.5px] text-muted-foreground">{connection.record.type}</span>
                        <span class="min-w-0">
                            <span class="block truncate font-mono text-[12px]" title={connection.record.name}>{connection.record.name}</span>
                            <span class="block truncate font-mono text-[12px] text-muted-foreground" title={connection.record.value}>{connection.record.value}</span>
                        </span>
                        <Button variant="outline" size="xs" aria-label="Copy the TXT value" onclick={() => copy(connection.record.value)}>
                            {copied === connection.record.value ? 'Copied' : 'Copy'}
                        </Button>
                    </div>
                {/if}

                <ToggleRow
                    label="Create accounts on first sign-in"
                    description="Someone at {connection.email_domain} without access gets an account and joins as a deployer."
                    checked={connection.jit_provisioning}
                    busy={provisioning}
                    disabled={!sso.can_manage}
                    onchange={setProvisioning}
                    bordered="top"
                    testid="sso-provisioning"
                />
            {:else}
                <ol class="m-0 flex list-none flex-col p-0 text-[13px]">
                    <li class="flex min-h-10 items-center gap-2.5 border-t border-border py-2">
                        <span class="w-5 shrink-0 font-mono text-[12px] text-muted-foreground">1</span>
                        <span>Create an OpenID Connect app at your identity provider with the redirect URIs here.</span>
                    </li>
                    <li class="flex min-h-10 items-center gap-2.5 border-t border-border py-2">
                        <span class="w-5 shrink-0 font-mono text-[12px] text-muted-foreground">2</span>
                        <span>Paste its issuer URL, client ID and client secret into Coresmith.</span>
                    </li>
                    <li class="flex min-h-10 items-center gap-2.5 border-t border-border py-2">
                        <span class="w-5 shrink-0 font-mono text-[12px] text-muted-foreground">3</span>
                        <span>Prove your email domain with a DNS record, then turn it on.</span>
                    </li>
                </ol>
            {/if}
        </div>

        <div class="flex min-w-0 flex-col" data-testid="sso-redirect-uris">
            <div class="flex min-h-10 items-center border-t border-border text-[12.5px] text-muted-foreground">Redirect URIs to register at your identity provider</div>
            {#each sso.redirect_uris as uri (uri)}
                <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
                    <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]" title={uri}>{uri}</span>
                    <Button variant="outline" size="xs" aria-label="Copy {uri}" onclick={() => copy(uri)}>{copied === uri ? 'Copied' : 'Copy'}</Button>
                </div>
            {/each}
        </div>
    </div>

    {#if sso.can_manage}
        <div class="mt-4 flex flex-wrap gap-2">
            {#if !connection}
                <Button variant="outline" size="sm" onclick={() => (editing = true)}>Set up single sign-on</Button>
            {:else}
                {#if !connection.verified}
                    <Button size="sm" disabled={checking} onclick={check}>{checking ? 'Checking…' : 'Check DNS'}</Button>
                {:else if connection.enabled}
                    <Button variant="outline" size="sm" disabled={switching} onclick={() => setEnabled(false)}>Turn off</Button>
                {:else}
                    <Button size="sm" disabled={switching} onclick={() => setEnabled(true)}>{switching ? 'Checking…' : 'Turn on'}</Button>
                {/if}
                <Button variant="ghost" size="sm" class="text-destructive" onclick={() => (removing = true)}>Remove</Button>
            {/if}
        </div>
    {:else}
        <p class="mt-4 mb-0 text-[12.5px] text-muted-foreground">Only an owner can change single sign-on.</p>
    {/if}
</div>

<Dialog.Root bind:open={editing}>
    <Dialog.Content>
        <Form method="put" action={route('settings.sso.update')} options={{ preserveScroll: true }} onSuccess={() => (editing = false)}>
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>{connection ? 'Edit single sign-on' : 'Set up single sign-on'}</Dialog.Title>
                    <Dialog.Description>Copy these details from the OpenID Connect app you created at your identity provider.</Dialog.Description>
                </Dialog.Header>
                <div class="mt-4 flex flex-col gap-3">
                    <div>
                        <Label for="sso-issuer" class="mb-1.5">Issuer URL</Label>
                        <Input
                            id="sso-issuer"
                            name="issuer"
                            value={connection?.issuer ?? ''}
                            required
                            placeholder="https://login.example.com"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.issuer ? true : undefined}
                        />
                        <FieldError message={errors.issuer} />
                    </div>
                    <div>
                        <Label for="sso-client-id" class="mb-1.5">Client ID</Label>
                        <Input
                            id="sso-client-id"
                            name="client_id"
                            value={connection?.client_id ?? ''}
                            required
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.client_id ? true : undefined}
                        />
                        <FieldError message={errors.client_id} />
                    </div>
                    <div>
                        <Label for="sso-client-secret" class="mb-1.5">Client secret</Label>
                        <Input
                            id="sso-client-secret"
                            name="client_secret"
                            type="password"
                            required={!connection}
                            placeholder={connection ? 'Saved, leave blank to keep it' : undefined}
                            autocomplete="new-password"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.client_secret ? true : undefined}
                            aria-describedby={connection ? 'sso-client-secret-hint' : undefined}
                        />
                        <FieldError message={errors.client_secret} />
                        {#if connection}
                            <p id="sso-client-secret-hint" class="mt-1.5 mb-0 text-[12px] text-muted-foreground">Enter it again if you change the issuer URL or client ID.</p>
                        {/if}
                    </div>
                    <div>
                        <Label for="sso-email-domain" class="mb-1.5">Email domain</Label>
                        <Input
                            id="sso-email-domain"
                            name="email_domain"
                            value={connection?.email_domain ?? ''}
                            required
                            placeholder="example.com"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.email_domain ? true : undefined}
                            aria-describedby="sso-email-domain-hint"
                        />
                        <FieldError message={errors.email_domain} />
                        <p id="sso-email-domain-hint" class="mt-1.5 mb-0 text-[12px] text-muted-foreground">
                            Only addresses at this domain can sign in. You prove you own it with a DNS record.
                        </p>
                    </div>
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (editing = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>Save</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={removing}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Remove single sign-on?</Dialog.Title>
            <Dialog.Description>
                People at {connection?.email_domain} sign in with a Coresmith password again. Anyone who never set one can choose it from the sign-in page.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (removing = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={remove}>Remove single sign-on</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
