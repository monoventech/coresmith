<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Form, router } from '@inertiajs/svelte'
    import { Check, ShieldCheck } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import ServerHeaderView from '@/components/servers/ServerHeader.svelte'
    import ToggleRow from '@/components/ToggleRow.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { ServerHeader } from '@/lib/servers'

    type Change = { id: number; action: string | null; port: number | null; from: string | null; at: string | null }

    let {
        server,
        isolation = [],
        hardening = [],
        firewall,
        access
    }: {
        server: ServerHeader
        isolation: string[]
        hardening: string[]
        firewall: { open: number[]; ssh_port: number; changes: Change[] }
        access: { ssh: string | null; fingerprint: string | null; public_key: string; audit_logging: boolean }
    } = $props()

    let auditBusy = $state(false)

    function setAudit(on: boolean) {
        auditBusy = true
        router.put(route('servers.update', server.id), { audit_logging_enabled: on }, { preserveScroll: true, onFinish: () => (auditBusy = false) })
    }

    function clock(iso: string | null): string {
        if (!iso) {
            return ''
        }

        const date = new Date(iso)
        const today = new Date().toDateString() === date.toDateString()

        return today ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) : date.toLocaleDateString([], { month: 'short', day: 'numeric' })
    }
</script>

<AppHead title="{server.name} hardening" />

<ServerHeaderView {server} />

<div class="grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-1">Nine-layer app isolation</h3>
        <p class="mb-3.5 text-[12.5px] text-muted-foreground">A compromise in one app must defeat all nine to reach another.</p>
        <ul class="m-0 flex list-none flex-col gap-2.25 p-0">
            {#each isolation as layer (layer)}
                <li class="flex items-center gap-2.5 text-[13.5px]"><Check class="size-4 shrink-0 text-success" />{layer}</li>
            {/each}
        </ul>
    </div>

    <div class="flex flex-col rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-1">Server hardening</h3>
        <p class="mb-3.5 text-[12.5px] text-muted-foreground">Applied during provisioning and verified before the server goes live.</p>
        <ul class="m-0 flex list-none flex-col gap-2.25 p-0">
            {#each hardening as item (item)}
                <li class="flex items-center gap-2.5 text-[13.5px]"><ShieldCheck class="size-4 shrink-0 text-success" />{item}</li>
            {/each}
        </ul>
        <div class="mt-auto pt-3">
            <ToggleRow
                label="Audit logging"
                description="auditd watches sudoers, SSH config, users and crontabs. Takes effect at the next provision."
                checked={access.audit_logging}
                busy={auditBusy}
                disabled={!server.can.update}
                onchange={setAudit}
                bordered="top"
            />
        </div>
    </div>
</div>

<div class="mt-4 grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <div class="flex flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="firewall">
        <h3 class="type-h3 m-0 mb-1">Firewall</h3>
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Default-deny. Provisioning opened <span class="font-mono text-[12px]">{firewall.open.join(', ')}</span>. Changes Coresmith applied are listed here.
        </p>
        <div class="flex flex-col">
            {#each firewall.changes as change (change.id)}
                <div class="flex items-baseline gap-3 border-t border-border py-2.5">
                    <span class="w-13 flex-none font-mono text-[12px] text-muted-foreground">{clock(change.at)}</span>
                    <span class="text-[13.5px]">
                        <span class="font-mono">{change.action} {change.port}</span><span class="text-muted-foreground">{change.from ? ' from ' : ''}</span><span class="font-mono">{change.from ?? ''}</span>
                    </span>
                </div>
            {:else}
                <p class="border-t border-border pt-3 text-[12.5px] text-muted-foreground">No changes since provisioning.</p>
            {/each}
        </div>
        {#if server.can.manageFirewall}
            <Form method="post" action={route('servers.firewall.store', server.id)} options={{ preserveScroll: true }} resetOnSuccess class="mt-auto pt-4">
                {#snippet children({ errors, processing })}
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="w-24">
                            <NativeSelect name="action" aria-label="Rule" options={[{ value: 'allow', label: 'Allow' }, { value: 'deny', label: 'Deny' }]} />
                        </div>
                        <div class="w-24">
                            <Input name="port" type="number" min="1" max="65535" placeholder="Port" aria-label="Port" required aria-invalid={errors.port ? true : undefined} />
                        </div>
                        <div class="min-w-36 flex-1">
                            <Input name="from_ip" placeholder="From IP, optional" aria-label="From IP" aria-invalid={errors.from_ip ? true : undefined} />
                        </div>
                        <Button type="submit" variant="outline" disabled={processing}>Apply</Button>
                    </div>
                    <FieldError message={errors.port ?? errors.from_ip} />
                    <p class="mt-2 text-[12px] text-muted-foreground">
                        Port <span class="font-mono">{firewall.ssh_port}</span> is how Coresmith reaches this server, so it can't be denied here.
                    </p>
                {/snippet}
            </Form>
        {/if}
    </div>

    <div class="flex flex-col rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-1">SSH access</h3>
        <p class="mb-3 text-[12.5px] text-muted-foreground">Coresmith signs in with a key it generated for this server alone. Password login is off.</p>
        <div class="font-mono text-[12.5px] leading-[1.9] text-muted-foreground">
            <div class="text-foreground">{access.ssh ?? 'no address yet'}</div>
            <div class="break-all">host key {access.fingerprint ?? 'not recorded yet'}</div>
        </div>
        <pre class="terminal mt-3 max-h-24 text-[11px] leading-[1.6] break-all whitespace-pre-wrap">{access.public_key}</pre>
    </div>
</div>
