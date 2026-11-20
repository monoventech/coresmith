<script module lang="ts">
    import type { DnsRecord } from '@/lib/dns'

    export type FrontDoor = {
        state: 'off' | 'opening' | 'open' | 'failed' | 'closing'
        doors: { key: string; name: string; ipv4: string }[]
        door: { name: string; url: string } | null
        hosts: string[]
        skipped: { host: string; reason: string }[]
        failing: { host: string; reason: string }[]
        records: DnsRecord[]
        error: string | null
        checked: string | null
        tunnel_on: boolean
        automatic_dns: boolean
        can_manage: boolean
    }
</script>

<script lang="ts">
    import DnsRecords from '@/components/DnsRecords.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Collapsible from '@/components/ui/collapsible'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import { cn } from '@/lib/utils'
    import { Form, router } from '@inertiajs/svelte'
    import { ChevronRight, CircleAlert } from '@lucide/svelte'

    let { frontDoor, server, name }: { frontDoor: FrontDoor; server: string; name: string } = $props()

    let opening = $state(false)
    let closing = $state(false)
    let sending = $state(false)

    const working = $derived(frontDoor.state === 'opening' || frontDoor.state === 'closing')
    const count = $derived(frontDoor.hosts.length === 1 ? '1 domain' : `${frontDoor.hosts.length} domains`)
    const failingCount = $derived(
        frontDoor.failing.length === 1 ? "This domain doesn't" : `These ${frontDoor.failing.length} domains don't`
    )
    const doorName = $derived(frontDoor.door?.name ?? 'the other server')

    const badge = $derived(
        {
            off: null,
            opening: { label: 'Setting up', variant: 'accent' as const },
            open: { label: 'On', variant: 'success' as const },
            failed: { label: "Didn't finish", variant: 'destructive' as const },
            closing: { label: 'Turning off', variant: 'accent' as const }
        }[frontDoor.state]
    )

    $effect(() => {
        if (!working) {
            return
        }

        const timer = setInterval(() => router.reload({ only: ['frontDoor'] }), 3000)

        return () => clearInterval(timer)
    })

    function checkAgain() {
        sending = true
        router.post(
            route('servers.front-door.store', server),
            {},
            { preserveScroll: true, onFinish: () => (sending = false) }
        )
    }

    function turnOff() {
        sending = true
        router.delete(route('servers.front-door.destroy', server), {
            preserveScroll: true,
            onSuccess: () => (closing = false),
            onFinish: () => (sending = false)
        })
    }
</script>

{#snippet doorLink()}
    {#if frontDoor.door}<a
            href={frontDoor.door.url}
            class="focus-ring rounded-sm font-medium text-primary-strong underline-offset-2 hover:underline"
            >{frontDoor.door.name}</a
        >{:else}{doorName}{/if}
{/snippet}

<section
    class="mb-4 flex flex-col rounded-xl border border-border bg-card px-5.5 py-5 max-sm:px-4"
    aria-labelledby="front-door-title"
    data-testid="front-door-card"
>
    <div class="mb-1 flex flex-wrap items-center gap-2.5">
        <h2 id="front-door-title" class="type-h3 m-0">IPv4 through another server</h2>
        {#if badge}
            <Badge variant={badge.variant} dot={working} pulse={working}>{badge.label}</Badge>
        {/if}
    </div>

    {#if frontDoor.state === 'off'}
        <p class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground">
            One of your other servers can take visitors in on its IPv4 address and pass them here over WireGuard.
            Uploads and slow pages are limited only by the two servers.
        </p>
        {#if frontDoor.tunnel_on}
            <p
                class="m-0 mt-2 max-w-prose text-[13px] leading-normal text-muted-foreground"
                data-testid="front-door-tunnel-on"
            >
                IPv4 through Cloudflare is on for this server. To take visitors through another server instead, turn
                that off first.
            </p>
        {:else if frontDoor.can_manage && frontDoor.doors.length === 0}
            <p
                class="m-0 mt-2 max-w-prose text-[13px] leading-normal text-muted-foreground"
                data-testid="front-door-no-doors"
            >
                None of your other servers has an IPv4 address the internet can reach yet.
            </p>
        {/if}
    {:else if frontDoor.state === 'opening'}
        <p class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground" role="status">
            Linking <span class="font-mono text-[12px]">{name}</span> and {@render doorLink()} over WireGuard. This takes
            a minute or two.
        </p>
    {:else if frontDoor.state === 'closing'}
        <p class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground" role="status">
            Unlinking <span class="font-mono text-[12px]">{name}</span> from {@render doorLink()}{frontDoor.automatic_dns
                ? ' and pointing the domains back here'
                : ''}.
        </p>
    {:else if frontDoor.state === 'open'}
        <p
            class="m-0 mt-1 max-w-prose text-[13px] leading-normal text-muted-foreground"
            data-testid="front-door-summary"
        >
            {#if frontDoor.hosts.length > 0}
                {count}
                {frontDoor.hosts.length === 1 ? 'reaches' : 'reach'} this server through {@render doorLink()}, over IPv4
                and IPv6.
            {:else}
                No domain reaches this server through {@render doorLink()} yet.
            {/if}
        </p>
        {#if frontDoor.hosts.length > 0}
            <Collapsible.Root class="mt-1.5">
                <Collapsible.Trigger
                    class="group focus-ring inline-flex min-h-8 items-center gap-1 rounded-md text-[12.5px] font-medium max-sm:min-h-11"
                    data-testid="front-door-hosts-trigger"
                >
                    <ChevronRight
                        class="size-3.5 transition-transform duration-150 group-data-[state=open]:rotate-90 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                    <span class="group-data-[state=open]:hidden">Show the domains</span>
                    <span class="hidden group-data-[state=open]:inline">Hide the domains</span>
                </Collapsible.Trigger>
                <Collapsible.Content>
                    <ul class="m-0 mt-1.5 flex list-none flex-col gap-1 p-0 pl-4.5" data-testid="front-door-hosts">
                        {#each frontDoor.hosts as host (host)}
                            <li class="font-mono text-[12.5px] wrap-anywhere">{host}</li>
                        {/each}
                    </ul>
                </Collapsible.Content>
            </Collapsible.Root>
        {/if}
    {/if}

    {#if frontDoor.state === 'open' && frontDoor.failing.length > 0}
        <div
            class="tint-warning mt-3 flex gap-2 rounded-lg border px-3.5 py-2.5 text-[12.5px] leading-normal"
            data-testid="front-door-failing"
        >
            <CircleAlert class="mt-0.5 size-3.75 shrink-0" aria-hidden="true" />
            <div class="flex min-w-0 flex-col gap-1.5">
                <p class="m-0">{failingCount} reach this server through {doorName} yet:</p>
                <ul class="m-0 flex list-none flex-col gap-1 p-0">
                    {#each frontDoor.failing as row (row.host)}
                        <li class="wrap-anywhere">{row.reason}</li>
                    {/each}
                </ul>
            </div>
        </div>
        {#if frontDoor.records.length > 0}
            <p class="m-0 mt-3 text-[12.5px] text-muted-foreground">
                Set these records where your domains' DNS is hosted, then choose Check again.
            </p>
            <div class="mt-1">
                <DnsRecords records={frontDoor.records} testid="front-door-records" />
            </div>
        {/if}
    {/if}

    {#if frontDoor.skipped.length > 0 && (frontDoor.state === 'open' || frontDoor.state === 'failed')}
        <div class="mt-3 flex flex-col gap-1.5 text-[12.5px]" data-testid="front-door-skipped">
            <p class="m-0 font-medium">Left as they were</p>
            {#each frontDoor.skipped as row (row.host)}
                <p class="m-0 wrap-anywhere text-muted-foreground">{row.reason}</p>
            {/each}
        </div>
    {/if}

    {#if frontDoor.error}
        <div
            class={cn(
                'mt-3 flex gap-2 rounded-lg border px-3.5 py-2.5 text-[12.5px] leading-normal',
                frontDoor.state === 'open' ? 'tint-warning' : 'tint-destructive'
            )}
            role="alert"
            data-testid="front-door-error"
        >
            <CircleAlert class="mt-0.5 size-3.75 shrink-0" aria-hidden="true" />
            <span class="wrap-anywhere"
                >{frontDoor.state === 'open'
                    ? `The last check didn't finish. ${frontDoor.error}`
                    : frontDoor.error}</span
            >
        </div>
    {/if}

    {#if frontDoor.state === 'open' && frontDoor.checked}
        <p class="m-0 mt-3 text-[12px] text-muted-foreground">Checked {frontDoor.checked}.</p>
    {/if}

    {#if frontDoor.can_manage && !working && !frontDoor.tunnel_on}
        <div class="mt-4 flex flex-wrap gap-2">
            {#if frontDoor.state === 'off' && frontDoor.doors.length > 0}
                <Button onclick={() => (opening = true)} data-testid="front-door-turn-on">Turn on</Button>
            {:else if frontDoor.state === 'off'}
                <Button variant="outline" href={route('servers.create')}>Add a server</Button>
            {:else if frontDoor.state === 'failed'}
                <Button disabled={sending} onclick={checkAgain}>{sending ? 'Trying again…' : 'Try again'}</Button>
                <Button variant="outline" onclick={() => (closing = true)}>Turn off</Button>
            {:else if frontDoor.state === 'open'}
                <Button variant="outline" size="sm" disabled={sending} onclick={checkAgain}
                    >{sending ? 'Checking…' : 'Check again'}</Button
                >
                <Button variant="ghost" size="sm" onclick={() => (closing = true)} data-testid="front-door-turn-off"
                    >Turn off</Button
                >
            {/if}
        </div>
    {:else if frontDoor.can_manage && frontDoor.state === 'closing' && frontDoor.error}
        <div class="mt-4">
            <Button variant="outline" disabled={sending} onclick={turnOff}>Try again</Button>
        </div>
    {:else if !frontDoor.can_manage && frontDoor.state === 'off'}
        <p class="m-0 mt-3 text-[12.5px] text-muted-foreground">A team owner can turn this on.</p>
    {/if}
</section>

<Dialog.Root bind:open={opening}>
    <Dialog.Content>
        <Form
            method="post"
            action={route('servers.front-door.store', server)}
            options={{ preserveScroll: true }}
            onSuccess={() => (opening = false)}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Take IPv4 visitors through another server</Dialog.Title>
                    <Dialog.Description>
                        {#if frontDoor.automatic_dns}
                            Coresmith links the two servers over WireGuard, sends {name}'s domains through the one you
                            pick, and points them at it in Cloudflare.
                        {:else}
                            Coresmith links the two servers over WireGuard and sends {name}'s domains through the one
                            you pick. Then you point each domain at it, and the card lists the records.
                        {/if}
                    </Dialog.Description>
                </Dialog.Header>
                <div class="mt-4">
                    <Label for="front-door-server" class="mb-1.5">Server</Label>
                    <NativeSelect
                        id="front-door-server"
                        name="door"
                        aria-describedby="front-door-server-hint"
                        aria-invalid={errors.door ? true : undefined}
                        options={frontDoor.doors.map((door) => ({
                            value: door.key,
                            label: `${door.name} (${door.ipv4})`
                        }))}
                    />
                    <FieldError message={errors.door} />
                    <p id="front-door-server-hint" class="mt-2 text-[12px] text-muted-foreground">
                        It takes visitors on ports 80 and 443 and links to {name} over UDP 51871. If its provider has a firewall
                        of its own, open UDP 51871 there too.
                    </p>
                </div>
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
            <Dialog.Title>Turn off IPv4 through {doorName}?</Dialog.Title>
            <Dialog.Description>
                {#if frontDoor.automatic_dns}
                    Coresmith points the domains back at {name}'s own addresses and unlinks it from {doorName}. People
                    without IPv6 can't reach them after that.
                {:else}
                    Coresmith unlinks {name} from {doorName}. Point the domains back at {name} yourself, or they stop loading.
                {/if}
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" type="button" onclick={() => (closing = false)}>Cancel</Button>
            <Button variant="destructive" disabled={sending} onclick={turnOff}
                >{sending ? 'Turning off…' : 'Turn off'}</Button
            >
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
