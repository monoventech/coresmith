<script lang="ts">
    import PlanNote from '@/components/settings/PlanNote.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Form, router } from '@inertiajs/svelte'

    type DnsRecord = { type: string; name: string; value: string }

    type Domain = {
        hostname: string
        url: string
        verified: boolean
        checked: string | null
        failure: string | null
        records: DnsRecord[]
    }

    let {
        domain = null,
        canUpdate,
        planNote = null
    }: { domain?: Domain | null; canUpdate: boolean; planNote?: string | null } = $props()

    let adding = $state(false)
    let removing = $state(false)
    let checking = $state(false)
    let working = $state(false)
    let copied = $state<string | null>(null)

    const failure = $derived(
        domain?.failure === 'wrong_value'
            ? 'The last check found a TXT record with a different value.'
            : domain?.failure === 'no_record'
              ? 'The last check found no TXT record yet.'
              : null
    )

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
        router.post(
            route('settings.team-domain.verify'),
            {},
            { preserveScroll: true, onFinish: () => (checking = false) }
        )
    }

    function remove() {
        working = true
        router.delete(route('settings.team-domain.destroy'), {
            preserveScroll: true,
            onFinish: () => ((working = false), (removing = false))
        })
    }
</script>

<div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="custom-domain-card">
    <div class="mb-1 flex flex-wrap items-center gap-2.5">
        <h3 class="type-h3 m-0">Custom domain</h3>
        {#if domain}
            <Badge variant={domain.verified ? 'success' : 'outline'} dot={domain.verified}
                >{domain.verified ? 'Verified' : 'Waiting for DNS'}</Badge
            >
        {/if}
    </div>

    {#if !domain}
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Open this team on a domain you own, like panel.example.com. Coresmith issues the certificate.
        </p>
        {#if planNote}
            <PlanNote note={planNote} />
        {:else if canUpdate}
            <div class="mt-auto self-start pt-2">
                <Button variant="outline" size="sm" onclick={() => (adding = true)}>Add a domain</Button>
            </div>
        {:else}
            <p class="text-[12.5px] text-muted-foreground">An owner or admin can add one.</p>
        {/if}
    {:else if domain.verified}
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            This team opens on its own domain. Signing in there is separate from this address.
        </p>
        <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
            <a
                href={domain.url}
                target="_blank"
                rel="noopener noreferrer"
                class="focus-ring min-w-0 flex-1 truncate rounded-sm font-mono text-[12.5px] text-foreground no-underline"
                >{domain.hostname}</a
            >
            <Badge variant="success">TLS</Badge>
        </div>
        {#if canUpdate}
            <div class="mt-auto flex flex-wrap gap-2 pt-3">
                <Button variant="ghost" size="sm" class="text-destructive-strong" onclick={() => (removing = true)}
                    >Remove</Button
                >
            </div>
        {/if}
    {:else}
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Add these records at your DNS provider for <span class="font-mono text-[12px]">{domain.hostname}</span>,
            then check them.
        </p>
        <div class="flex flex-col" data-testid="dns-records">
            {#each domain.records as record (record.type)}
                <div class="grid grid-cols-[52px_1fr_auto] items-center gap-2.5 border-t border-border py-2">
                    <span class="font-mono text-[11.5px] text-muted-foreground">{record.type}</span>
                    <span class="min-w-0">
                        <span class="block truncate font-mono text-[12px]" title={record.name}>{record.name}</span>
                        <span class="block truncate font-mono text-[12px] text-muted-foreground" title={record.value}
                            >{record.value}</span
                        >
                    </span>
                    <button
                        type="button"
                        class="focus-ring quick-action h-8! px-3! text-[12.5px]!"
                        aria-label="Copy the {record.type} value"
                        onclick={() => copy(record.value)}
                    >
                        {copied === record.value ? 'Copied' : 'Copy'}
                    </button>
                </div>
            {/each}
        </div>
        {#if failure}
            <p class="mt-2 text-[12px] text-muted-foreground" data-testid="dns-failure">{failure}</p>
        {/if}
        {#if canUpdate}
            <div class="mt-auto flex flex-wrap gap-2 pt-3">
                <Button size="sm" disabled={checking} onclick={check}>{checking ? 'Checking…' : 'Check DNS'}</Button>
                <Button variant="ghost" size="sm" class="text-destructive-strong" onclick={() => (removing = true)}
                    >Remove</Button
                >
            </div>
        {/if}
    {/if}
</div>

<Dialog.Root bind:open={adding}>
    <Dialog.Content>
        <Form
            method="post"
            action={route('settings.team-domain.store')}
            options={{ preserveScroll: true }}
            onSuccess={() => (adding = false)}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Add a custom domain</Dialog.Title>
                    <Dialog.Description
                        >Use a hostname you control. You prove it with a DNS record before it goes live.</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4">
                    <Label for="custom-domain" class="mb-1.5">Domain</Label>
                    <Input
                        id="custom-domain"
                        name="hostname"
                        required
                        placeholder="panel.example.com"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-mono text-[13px]"
                        aria-invalid={errors.hostname ? true : undefined}
                    />
                    <FieldError message={errors.hostname} />
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (adding = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>Add domain</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={removing}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Remove {domain?.hostname}?</Dialog.Title>
            <Dialog.Description
                >The domain stops opening this panel. Your team address keeps working.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (removing = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={remove}>Remove domain</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
