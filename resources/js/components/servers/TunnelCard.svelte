<script module lang="ts">
    export type Tunnel = {
        state: 'off' | 'opening' | 'open' | 'failed' | 'closing'
        ipv4: string | null
        hosts: string[]
        skipped: { host: string; reason: string }[]
        diverted: { host: string; to: string }[]
        error: string | null
        checked: string | null
        cloudflare: boolean
        front_door: string | null
        can_manage: boolean
    }
</script>

<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Collapsible from '@/components/ui/collapsible'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { tunnelTokenLink } from '@/lib/cloudflare'
    import { cn } from '@/lib/utils'
    import { Form, router } from '@inertiajs/svelte'
    import { ChevronRight, CircleAlert } from '@lucide/svelte'

    let { tunnel, server, name }: { tunnel: Tunnel; server: string; name: string } = $props()

    let opening = $state(false)
    let closing = $state(false)
    let sending = $state(false)

    const working = $derived(tunnel.state === 'opening' || tunnel.state === 'closing')
    const reaching = $derived(tunnel.hosts.filter((host) => !tunnel.diverted.some((row) => row.host === host)).length)
    const count = $derived(reaching === 1 ? '1 domain' : `${reaching} domains`)
    const diverted = $derived(tunnel.diverted.length === 1 ? 'this domain' : `these ${tunnel.diverted.length} domains`)

    const badge = $derived(
        {
            off: { label: 'IPv4 closed', variant: 'warning' as const },
            opening: { label: 'Setting up', variant: 'accent' as const },
            open: { label: 'On', variant: 'success' as const },
            failed: { label: "Didn't finish", variant: 'destructive' as const },
            closing: { label: 'Turning off', variant: 'accent' as const }
        }[tunnel.state]
    )

    $effect(() => {
        if (!working) {
            return
        }

        const timer = setInterval(() => router.reload({ only: ['tunnel'] }), 3000)

        return () => clearInterval(timer)
    })

    function checkAgain() {
        sending = true
        router.post(
            route('servers.tunnel.store', server),
            {},
            { preserveScroll: true, onFinish: () => (sending = false) }
        )
    }

    function turnOff() {
        sending = true
        router.delete(route('servers.tunnel.destroy', server), {
            preserveScroll: true,
            onSuccess: () => (closing = false),
            onFinish: () => (sending = false)
        })
    }
</script>

<section
    class="mb-4 flex flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:px-4"
    aria-labelledby="tunnel-title"
    data-testid="tunnel-card"
>
    <div class="mb-1 flex flex-wrap items-center gap-2.5">
        <h2 id="tunnel-title" class="type-h3 m-0">IPv4 through Cloudflare</h2>
        {#if !tunnel.front_door}
            <Badge variant={badge.variant} dot={working} pulse={working}>{badge.label}</Badge>
        {/if}
    </div>

    {#if tunnel.front_door}
        <p class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground" data-testid="tunnel-door-on">
            IPv4 through {tunnel.front_door} is on for this server. To bring visitors in through Cloudflare instead, turn
            that off first.
        </p>
    {:else if tunnel.state === 'off'}
        <p class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground">
            The internet can't reach
            {#if tunnel.ipv4}<span class="font-mono text-[12px] text-foreground">{tunnel.ipv4}</span>{:else}this
                server's IPv4 address{/if} on ports 80 and 443, so people without IPv6 can't open the apps on this server.
            That happens when your provider shares one IPv4 address between customers, or when your router doesn't forward
            those ports.
        </p>
        <p class="m-0 mt-2 max-w-prose text-[13px] leading-normal text-muted-foreground">
            Coresmith can bring those visitors in through a Cloudflare Tunnel. It needs no open ports, and Coresmith
            points each domain here at it.
        </p>
    {:else if tunnel.state === 'opening'}
        <p class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground" role="status">
            Installing Cloudflare's connector on <span class="font-mono text-[12px]">{name}</span> and pointing the domains
            here at it. This takes a minute or two.
        </p>
    {:else if tunnel.state === 'closing'}
        <p class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground" role="status">
            Pointing the domains back at <span class="font-mono text-[12px]">{name}</span> and removing the tunnel.
        </p>
    {:else if tunnel.state === 'open'}
        <p class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground" data-testid="tunnel-summary">
            {#if reaching > 0}
                {count}
                {reaching === 1 ? 'reaches' : 'reach'} this server through Cloudflare, over IPv4 and IPv6.
            {:else if tunnel.hosts.length > 0}
                No domain on the tunnel reaches this server yet.
            {:else}
                The tunnel is on, but no domain goes through it yet. Domains you add to apps here join it on their own.
            {/if}
        </p>
        {#if tunnel.hosts.length > 0}
            <Collapsible.Root class="mt-1.5">
                <Collapsible.Trigger
                    class="group focus-ring inline-flex min-h-8 items-center gap-1 rounded-md text-[12.5px] font-medium max-sm:min-h-11"
                    data-testid="tunnel-hosts-trigger"
                >
                    <ChevronRight
                        class="size-3.5 transition-transform duration-150 group-data-[state=open]:rotate-90 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                    <span class="group-data-[state=open]:hidden">Show the domains</span>
                    <span class="hidden group-data-[state=open]:inline">Hide the domains</span>
                </Collapsible.Trigger>
                <Collapsible.Content>
                    <ul class="m-0 mt-1.5 flex list-none flex-col gap-1 p-0 pl-4.5" data-testid="tunnel-hosts">
                        {#each tunnel.hosts as host (host)}
                            <li class="font-mono text-[12.5px] wrap-anywhere">{host}</li>
                        {/each}
                    </ul>
                </Collapsible.Content>
            </Collapsible.Root>
        {/if}
    {/if}

    {#if tunnel.skipped.length > 0 && (tunnel.state === 'open' || tunnel.state === 'failed')}
        <div class="mt-3 flex flex-col gap-1.5 text-[12.5px]" data-testid="tunnel-skipped">
            <p class="m-0 font-medium">Left as they were</p>
            {#each tunnel.skipped as row (row.host)}
                <p class="m-0 wrap-anywhere text-muted-foreground">{row.reason}</p>
            {/each}
        </div>
    {/if}

    {#if tunnel.state === 'open' && tunnel.diverted.length > 0}
        <div
            class="tint-warning mt-3 flex gap-2 rounded-lg border px-3.5 py-2.5 text-[12.5px] leading-normal"
            data-testid="tunnel-diverted"
        >
            <CircleAlert class="mt-0.5 size-3.75 shrink-0" aria-hidden="true" />
            <div class="flex min-w-0 flex-col gap-1.5">
                <p class="m-0">
                    Cloudflare redirects {diverted} to another site, so visitors never reach this server:
                </p>
                <ul class="m-0 flex list-none flex-col gap-1 p-0">
                    {#each tunnel.diverted as row (row.host)}
                        <li class="wrap-anywhere">
                            <span class="font-mono text-[12px]">{row.host}</span> goes to
                            <span class="font-mono text-[12px]">{row.to}</span>
                        </li>
                    {/each}
                </ul>
                <p class="m-0">
                    If that isn't on purpose, delete the redirect in Cloudflare. Look in Redirect Rules, Page Rules and
                    Bulk Redirects. Then choose Check again.
                </p>
            </div>
        </div>
    {/if}

    {#if tunnel.error}
        <div
            class={cn(
                'mt-3 flex gap-2 rounded-lg border px-3.5 py-2.5 text-[12.5px] leading-normal',
                tunnel.state === 'open' ? 'tint-warning' : 'tint-destructive'
            )}
            role="alert"
            data-testid="tunnel-error"
        >
            <CircleAlert class="mt-0.5 size-3.75 shrink-0" aria-hidden="true" />
            <span class="wrap-anywhere"
                >{tunnel.state === 'open' ? `The last check didn't finish. ${tunnel.error}` : tunnel.error}</span
            >
        </div>
    {/if}

    {#if tunnel.state === 'open' && tunnel.checked}
        <p class="m-0 mt-3 text-[12px] text-muted-foreground">Checked {tunnel.checked}.</p>
    {/if}

    {#if tunnel.can_manage && !working && !tunnel.front_door}
        <div class="mt-4 flex flex-wrap gap-2">
            {#if tunnel.state === 'off'}
                <Button onclick={() => (opening = true)} data-testid="tunnel-turn-on">Turn on</Button>
            {:else if tunnel.state === 'failed'}
                <Button onclick={() => (opening = true)}>Try again</Button>
                <Button variant="outline" onclick={() => (closing = true)}>Turn off</Button>
            {:else if tunnel.state === 'open'}
                <Button variant="outline" size="sm" disabled={sending} onclick={checkAgain}
                    >{sending ? 'Checking…' : 'Check again'}</Button
                >
                <Button variant="ghost" size="sm" onclick={() => (closing = true)} data-testid="tunnel-turn-off"
                    >Turn off</Button
                >
            {/if}
        </div>
    {:else if tunnel.can_manage && tunnel.state === 'closing' && tunnel.error}
        <div class="mt-4">
            <Button variant="outline" disabled={sending} onclick={turnOff}>Try again</Button>
        </div>
    {:else if !tunnel.can_manage && tunnel.state === 'off' && !tunnel.front_door}
        <p class="m-0 mt-3 text-[12.5px] text-muted-foreground">Someone who manages Coresmith can turn this on.</p>
    {/if}
</section>

<Dialog.Root bind:open={opening}>
    <Dialog.Content>
        <Form
            method="post"
            action={route('servers.tunnel.store', server)}
            options={{ preserveScroll: true }}
            onSuccess={() => (opening = false)}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Turn on IPv4 through Cloudflare</Dialog.Title>
                    <Dialog.Description
                        >Coresmith makes a Cloudflare Tunnel for {name}, installs Cloudflare's connector there and
                        points each domain on this server at the tunnel. Records that point somewhere else stay as they
                        are.</Dialog.Description
                    >
                </Dialog.Header>
                {#if tunnel.cloudflare}
                    <p class="mt-4 text-[12.5px] text-muted-foreground">
                        Coresmith uses its Cloudflare token. In Cloudflare it needs Account, Cloudflare Tunnel, Edit,
                        and DNS edit for your domains' zones.
                    </p>
                    <FieldError message={errors.token} />
                {:else}
                    <div class="mt-4">
                        <Label for="tunnel-token" class="mb-1.5">Cloudflare API token</Label>
                        <Input
                            id="tunnel-token"
                            name="token"
                            type="password"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-describedby="tunnel-token-hint"
                            aria-invalid={errors.token ? true : undefined}
                        />
                        <FieldError message={errors.token} />
                        <p id="tunnel-token-hint" class="mt-2 text-[12px] text-muted-foreground">
                            <a
                                class="focus-ring rounded-sm font-medium text-primary-strong underline-offset-2 hover:underline"
                                href={tunnelTokenLink}
                                target="_blank"
                                rel="noopener noreferrer"
                                data-testid="tunnel-token-link"
                                >Create one in Cloudflare<span class="sr-only"> (opens in a new tab)</span></a
                            > with Account, Cloudflare Tunnel, Edit and Zone, DNS, Edit already filled in, then paste it here.
                            Coresmith keeps it encrypted and never shows it again.
                        </p>
                    </div>
                {/if}
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (opening = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>{processing ? 'Turning on…' : 'Turn on'}</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={closing}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Turn off the tunnel?</Dialog.Title>
            <Dialog.Description
                >{#if tunnel.hosts.length > 0}Coresmith points {count} back at {name}'s own addresses, then removes the
                    tunnel and its connector. People without IPv6 can't reach them after that.{:else}Coresmith removes
                    the tunnel and its connector from {name}.{/if}</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" type="button" onclick={() => (closing = false)}>Cancel</Button>
            <Button variant="destructive" disabled={sending} onclick={turnOff}
                >{sending ? 'Turning off…' : 'Turn off'}</Button
            >
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
