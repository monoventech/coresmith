<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { Address } from '@/lib/address'
    import { Form, router } from '@inertiajs/svelte'
    import { CircleAlert, CircleCheck, Info } from '@lucide/svelte'

    let { address, canManage }: { address: Address; canManage: boolean } = $props()

    let changing = $state(false)
    let domain = $state('')
    let checkedFor = $state<string | null>(null)
    let checking = $state(false)
    let pointing = $state(false)
    let connecting = $state(false)

    const result = $derived(checkedFor !== null && checkedFor === domain ? address.check : null)

    const running = $derived(address.change?.state === 'running')
    const failed = $derived(address.change?.state === 'failed' ? address.change : null)
    const changeable = $derived(canManage && address.managed && !(running || failed))
    const moved = $derived(
        address.change?.state === 'done' && address.change.to === address.host ? address.change : null
    )
    const stale = $derived(moved !== null && typeof window !== 'undefined' && window.location.hostname !== address.host)

    const certificate = $derived(
        {
            public: {
                badge: 'Trusted certificate',
                tone: 'success' as const,
                note: 'Where people open Coresmith. Caddy keeps its certificate renewed.'
            },
            tailscale: {
                badge: 'Tailscale certificate',
                tone: 'success' as const,
                note: 'Where people on your tailnet open Coresmith. Tailscale issues its certificate.'
            },
            internal: {
                badge: 'Browsers warn',
                tone: 'warning' as const,
                note: 'Where people open Coresmith. Caddy signs this certificate itself, so browsers warn until Coresmith has a domain or a Tailscale name.'
            }
        }[address.certificate]
    )

    function change(value = '') {
        domain = value
        checkedFor = null
        changing = true
    }

    function point() {
        pointing = true

        router.post(
            route('settings.address.point'),
            { address: domain },
            { preserveScroll: true, only: ['address'], onFinish: () => (pointing = false) }
        )
    }

    function disconnect() {
        router.delete(route('settings.address.cloudflare.destroy'), { preserveScroll: true, only: ['address'] })
    }

    function check() {
        const asked = domain
        checking = true

        router.post(
            route('settings.address.check'),
            { address: asked },
            {
                preserveScroll: true,
                only: ['address'],
                onSuccess: () => (checkedFor = asked),
                onFinish: () => (checking = false)
            }
        )
    }

    $effect(() => {
        if (!running) {
            return
        }

        const timer = setInterval(
            () =>
                router.reload({
                    only: ['address'],
                    onNetworkError: () => {
                        window.location.reload()

                        return false
                    }
                }),
            3000
        )

        return () => clearInterval(timer)
    })
</script>

<div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="address-card">
    <div class="mb-1 flex flex-wrap items-center gap-2.5">
        <h3 class="type-h3 m-0">Address</h3>
        {#if running}
            <Badge variant="accent" dot pulse>Switching</Badge>
        {:else}
            <Badge variant={certificate.tone}>{certificate.badge}</Badge>
        {/if}
    </div>
    <p class="mb-3 text-[12.5px] text-muted-foreground">{certificate.note}</p>

    <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
        <a
            href={address.url}
            class="focus-ring min-w-0 flex-1 truncate rounded-sm font-mono text-[12.5px] text-foreground no-underline"
            >{address.host}</a
        >
        {#if changeable}
            <Button variant="outline" size="sm" onclick={() => change()}>Change</Button>
        {/if}
    </div>

    {#if running && address.change}
        <p class="mt-2 text-[12.5px] text-muted-foreground" role="status">
            Switching to <span class="font-mono text-[12px]">{address.change.to}</span>.
            <span class="font-mono text-[12px]">{address.change.from}</span> keeps working until the certificate is ready.
        </p>
    {:else if failed}
        <div
            class="tint-destructive mt-2 rounded-lg border px-3.5 py-3 text-[12.5px] leading-normal"
            role="alert"
            data-testid="address-error"
        >
            {failed.error}
        </div>
        {#if canManage && address.managed}
            <div class="mt-3 self-start">
                <Button variant="outline" size="sm" onclick={() => change(failed.to)}>Try again</Button>
            </div>
        {/if}
    {:else if moved}
        <p class="mt-2 text-[12.5px] text-muted-foreground" role="status">
            Moved from <span class="font-mono text-[12px]">{moved.from}</span>, which now sends people here.
        </p>
        {#if stale}
            <div class="mt-3 self-start">
                <Button size="sm" href={address.url}>Open the new address</Button>
            </div>
        {/if}
        {#if address.follow?.github || address.follow?.sso}
            <ul class="mt-3 mb-0 flex list-none flex-col gap-2 p-0 text-[12.5px]" data-testid="address-follow">
                {#if address.follow.github}
                    <li>
                        GitHub still sends events to the old address.
                        <a href={address.follow.github} class="focus-ring rounded-sm font-medium text-primary-strong"
                            >Point the webhook here from Git settings</a
                        >.
                    </li>
                {/if}
                {#if address.follow.sso}
                    <li>
                        Add <span class="font-mono text-[12px] wrap-anywhere">{address.follow.sso}</span> as a redirect URI
                        at your identity provider, or single sign-on stops working.
                    </li>
                {/if}
            </ul>
        {/if}
    {/if}

    {#if canManage && address.managed}
        <div class="mt-4 border-t border-border pt-3.5 text-[12.5px]" data-testid="address-dns">
            {#if address.cloudflare.connected}
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="m-0 font-medium">Cloudflare DNS</p>
                        {#if address.cloudflare.error}
                            <p class="m-0 mt-1 flex gap-2 text-destructive-strong">
                                <CircleAlert class="mt-0.5 size-3.75 shrink-0" />
                                <span class="wrap-anywhere">{address.cloudflare.error}</span>
                            </p>
                        {:else if address.cloudflare.host && address.cloudflare.synced}
                            <p class="m-0 mt-1 text-muted-foreground">
                                Keeps <span class="font-mono text-[12px] wrap-anywhere">{address.cloudflare.host}</span>
                                pointed at this server. Checked {address.cloudflare.synced}.
                            </p>
                        {:else}
                            <p class="m-0 mt-1 text-muted-foreground">
                                Connected. Coresmith keeps its domain pointed at this server, checking every ten
                                minutes.
                            </p>
                        {/if}
                    </div>
                    <Button variant="outline" size="sm" onclick={disconnect}>Disconnect</Button>
                </div>
            {:else}
                <div class="flex items-start justify-between gap-3">
                    <p class="m-0 text-muted-foreground">
                        Coresmith can keep its domain's DNS records pointed here, with a Cloudflare API token.
                    </p>
                    <Button variant="outline" size="sm" onclick={() => (connecting = true)}>Connect Cloudflare</Button>
                </div>
            {/if}
        </div>
    {/if}

    {#if canManage && !address.managed}
        <p class="mt-3 mb-2 text-[12.5px] text-muted-foreground">
            To change it, run the installer again on this server with the new domain:
        </p>
        <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
        <pre
            class="terminal text-[11.5px] leading-[1.7] wrap-anywhere whitespace-pre-wrap"
            tabindex="0"
            role="region"
            aria-label="Command that changes the address">curl -fsSL https://get.coresmith.io/install | sudo bash -s -- --domain coresmith.example.com</pre>
    {/if}
</div>

<Dialog.Root bind:open={changing}>
    <Dialog.Content>
        <Form
            method="put"
            action={route('settings.address.update')}
            options={{ preserveScroll: true }}
            onSuccess={() => (changing = false)}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Change Coresmith's address</Dialog.Title>
                    <Dialog.Description
                        >Check the domain first: Coresmith tests it from outside and says what to fix. Once it passes,
                        Coresmith switches when Caddy has a certificate for it, and the current address then sends
                        people there. You'll sign in again at the new address.</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4">
                    <Label for="address" class="mb-1.5">Domain</Label>
                    <Input
                        id="address"
                        name="address"
                        bind:value={domain}
                        required
                        placeholder="coresmith.example.com"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-mono text-[13px]"
                        aria-describedby="address-hint"
                        aria-invalid={errors.address ? true : undefined}
                    />
                    <FieldError message={errors.address} />
                    <p id="address-hint" class="mt-2 text-[12px] text-muted-foreground">
                        A Tailscale name like beelink.tail1234.ts.net works too.
                    </p>
                    {#if result}
                        <div class="mt-3 flex flex-col gap-2 text-[12.5px]" role="status" data-testid="address-check">
                            {#if result.ok}
                                <p class="m-0 flex gap-2">
                                    <CircleCheck class="mt-0.5 size-3.75 shrink-0 text-success" />
                                    <span
                                        >Coresmith can move to <span class="font-mono text-[12px] wrap-anywhere"
                                            >{result.host}</span
                                        >.</span
                                    >
                                </p>
                            {:else}
                                {#each result.problems as problem (problem)}
                                    <p class="m-0 flex gap-2 text-destructive-strong">
                                        <CircleAlert class="mt-0.5 size-3.75 shrink-0" />
                                        <span class="wrap-anywhere">{problem}</span>
                                    </p>
                                {/each}
                                {#if address.cloudflare.connected}
                                    <div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            type="button"
                                            disabled={pointing}
                                            onclick={point}
                                            >{pointing ? 'Pointing…' : 'Point it here with Cloudflare'}</Button
                                        >
                                    </div>
                                {/if}
                            {/if}
                            {#each result.notes as note (note)}
                                <p class="m-0 flex gap-2 text-muted-foreground">
                                    <Info class="mt-0.5 size-3.75 shrink-0" />
                                    <span class="wrap-anywhere">{note}</span>
                                </p>
                            {/each}
                        </div>
                    {/if}
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (changing = false)}>Cancel</Button>
                    <Button variant="outline" type="button" disabled={checking || domain.trim() === ''} onclick={check}
                        >{checking ? 'Checking…' : result ? 'Check again' : 'Check'}</Button
                    >
                    <Button type="submit" disabled={processing || !result?.ok}
                        >{processing ? 'Switching…' : 'Switch address'}</Button
                    >
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={connecting}>
    <Dialog.Content>
        <Form
            method="post"
            action={route('settings.address.cloudflare.store')}
            options={{ preserveScroll: true, only: ['address'] }}
            onSuccess={() => (connecting = false)}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Connect Cloudflare</Dialog.Title>
                    <Dialog.Description
                        >Create a token in Cloudflare under My Profile, API Tokens, from the Edit zone DNS template,
                        limited to your domain's zone. Coresmith keeps it encrypted and never shows it again.</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4">
                    <Label for="cloudflare-token" class="mb-1.5">API token</Label>
                    <Input
                        id="cloudflare-token"
                        name="token"
                        type="password"
                        required
                        autocomplete="off"
                        spellcheck="false"
                        class="font-mono text-[13px]"
                        aria-invalid={errors.token ? true : undefined}
                    />
                    <FieldError message={errors.token} />
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (connecting = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>{processing ? 'Connecting…' : 'Connect'}</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>
