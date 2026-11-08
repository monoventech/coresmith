<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { ServerHeader } from '@/lib/servers'
    import { Form } from '@inertiajs/svelte'
    import { CircleCheck } from '@lucide/svelte'

    let { server, onretry, onrestart }: { server: ServerHeader; onretry: () => void; onrestart: () => void } = $props()

    const provisioning = $derived(server.provisioning!)
    const failed = $derived(provisioning.status === 'failed')
    const offersPassword = $derived(provisioning.password_sign_in && server.can.update)

    let copied = $state<'command' | 'key' | null>(null)
    let blocked = $state(false)

    async function copy(what: 'command' | 'key') {
        try {
            await navigator.clipboard.writeText(
                what === 'command' ? provisioning.connect_command : provisioning.public_key
            )
            blocked = false
            copied = what
            setTimeout(() => (copied = null), 2000)
        } catch {
            copied = null
            blocked = true
        }
    }
</script>

<div class="rounded-xl border border-border bg-card p-6" data-testid="server-connect">
    <h3 class="type-h3 m-0 mb-1">{provisioning.connected ? 'Connection' : `Connect ${server.name}`}</h3>

    {#if provisioning.connected}
        <p class="mb-4 flex items-center gap-2 text-[12.5px] text-muted-foreground">
            <CircleCheck class="size-3.75 shrink-0 text-success" />
            <span
                >Connected. Coresmith signs in as <span class="font-mono text-[12px]">{provisioning.ssh_user}</span> with
                its own key.</span
            >
        </p>
    {:else}
        <p class="mb-4 text-[12.5px] text-muted-foreground">
            {offersPassword
                ? 'Coresmith needs its own key on the server. It can add the key itself with a password, or you can run one command.'
                : 'Coresmith needs its own key on the server.'}
        </p>

        {#if provisioning.connect_error}
            <div
                class="tint-destructive mb-4 rounded-lg border px-3.5 py-3 text-[12.5px] leading-normal"
                role="alert"
                data-testid="connect-error"
            >
                {provisioning.connect_error}
            </div>
        {/if}

        {#if offersPassword}
            <Form
                method="post"
                action={route('servers.connect', server.id)}
                options={{ preserveScroll: true }}
                resetOnSuccess={['password']}
                resetOnError={['password']}
                class="mb-5 border-b border-border pb-5"
            >
                {#snippet children({ errors, processing })}
                    <h4 class="m-0 mb-1 text-[14px] font-medium">With a password</h4>
                    <p id="connect-password-hint" class="mb-3 text-[12px] text-muted-foreground">
                        Used once to add the key for root, then forgotten. Hardening turns password logins off.
                    </p>
                    <div class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3">
                        <div>
                            <Label for="connect-user" class="mb-1.5">SSH user</Label>
                            <Input
                                id="connect-user"
                                name="ssh_user"
                                value={provisioning.ssh_user}
                                autocomplete="off"
                                spellcheck="false"
                                required
                                aria-invalid={errors.ssh_user ? true : undefined}
                            />
                            <FieldError message={errors.ssh_user} />
                        </div>
                        <div>
                            <Label for="connect-password" class="mb-1.5">Password</Label>
                            <Input
                                id="connect-password"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                required
                                aria-describedby="connect-password-hint"
                                aria-invalid={errors.password ? true : undefined}
                            />
                            <FieldError message={errors.password} />
                        </div>
                    </div>
                    <Button type="submit" size="sm" class="mt-3" disabled={processing}
                        >{processing ? 'Connecting…' : 'Connect'}</Button
                    >
                {/snippet}
            </Form>
        {/if}

        <h4 class="m-0 mb-1 text-[14px] font-medium">
            {offersPassword ? 'Or run this on the server' : 'Run this on the server'}
        </h4>
        <p class="mb-3 text-[12px] text-muted-foreground">
            As a user with sudo, or as root without the sudo. It adds the key for
            <span class="font-mono text-[11.5px]">{provisioning.ssh_user}</span>.
        </p>
        <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
        <pre
            class="terminal text-[11.5px] leading-[1.7] wrap-anywhere whitespace-pre-wrap"
            data-testid="connect-command"
            tabindex="0"
            role="region"
            aria-label="Command that adds Coresmith's key">{provisioning.connect_command}</pre>
        {#if provisioning.ssh}
            <p class="mt-2 font-mono text-[12px] text-muted-foreground">{provisioning.ssh}</p>
        {/if}
        {#if blocked}
            <p class="mt-2 text-[12px] text-muted-foreground" role="status">
                This browser blocked copying. Select the command above and copy it yourself.
            </p>
        {/if}
    {/if}

    <div class="mt-4 flex flex-wrap gap-2">
        {#if !provisioning.connected}
            <Button variant="outline" size="sm" onclick={() => copy('command')}
                >{copied === 'command' ? 'Copied' : 'Copy the command'}</Button
            >
            <Button variant="outline" size="sm" onclick={() => copy('key')}
                >{copied === 'key' ? 'Copied' : 'Copy the key'}</Button
            >
        {/if}
        {#if server.can.update}
            <Form method="post" action={route('servers.test-connection', server.id)} options={{ preserveScroll: true }}>
                {#snippet children({ processing })}
                    <Button type="submit" variant="outline" size="sm" disabled={processing}
                        >{processing ? 'Testing…' : 'Test connection'}</Button
                    >
                {/snippet}
            </Form>
            {#if failed}
                <Button size="sm" onclick={onretry}>{`Retry from phase ${provisioning.failed_phase}`}</Button>
                <Button variant="outline" size="sm" onclick={onrestart}>Start over</Button>
            {/if}
        {/if}
    </div>
</div>
