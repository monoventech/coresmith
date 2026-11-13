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
        admin_move_error = null,
        ssh = {
            verified: false,
            owner: false,
            passwords_off: false,
            error: null,
            logins: null,
            source: null,
            lines: [],
            reload: 'ssh',
            checked: null
        },
        firewall,
        access
    }: {
        server: ServerHeader
        isolation: string[]
        hardening: string[]
        admin_move_error?: string | null
        ssh?: {
            verified: boolean
            owner: boolean
            passwords_off: boolean
            error: string | null
            logins: { password: boolean; keyboard: boolean; root: boolean } | null
            source: string | null
            lines: string[]
            reload: string
            checked: string | null
        }
        firewall: { open: number[]; kept_ssh: boolean; ssh_port: number; changes: Change[] }
        access: { ssh: string | null; fingerprint: string | null; public_key: string; audit_logging: boolean }
    } = $props()

    let auditBusy = $state(false)
    let checking = $state(false)

    const quoted = (line: string) => `'${line}'`
    const commands = $derived(
        `printf '%s\\n' ${ssh.lines.map(quoted).join(' ')} | sudo tee /etc/ssh/sshd_config.d/01-no-passwords.conf\nsudo sshd -t && sudo systemctl reload ${ssh.reload}`
    )

    function checkAgain() {
        checking = true
        router.post(
            route('servers.ssh-check', server.id),
            {},
            { preserveScroll: true, onFinish: () => (checking = false) }
        )
    }

    function setAudit(on: boolean) {
        auditBusy = true
        router.put(
            route('servers.update', server.id),
            { audit_logging_enabled: on },
            { preserveScroll: true, onFinish: () => (auditBusy = false) }
        )
    }

    function clock(iso: string | null): string {
        if (!iso) {
            return ''
        }

        const date = new Date(iso)
        const today = new Date().toDateString() === date.toDateString()

        return today
            ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false })
            : date.toLocaleDateString([], { month: 'short', day: 'numeric' })
    }
</script>

<AppHead title="{server.name} hardening" />

<ServerHeaderView {server} />

<div class="grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-1">Nine-layer app isolation</h3>
        <p class="mb-3.5 text-[12.5px] text-muted-foreground">
            A compromise in one app must defeat all nine to reach another.
        </p>
        <ul class="m-0 flex list-none flex-col gap-2.25 p-0">
            {#each isolation as layer (layer)}
                <li class="flex items-center gap-2.5 text-[13.5px]">
                    <Check class="size-4 shrink-0 text-success" />{layer}
                </li>
            {/each}
        </ul>
    </div>

    <div class="flex flex-col rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-1">Server hardening</h3>
        <p class="mb-3.5 text-[12.5px] text-muted-foreground">
            Applied during provisioning and verified before the server goes live.
        </p>
        <ul class="m-0 flex list-none flex-col gap-2.25 p-0">
            {#each hardening as item (item)}
                <li class="flex items-center gap-2.5 text-[13.5px]">
                    <ShieldCheck class="size-4 shrink-0 text-success" />{item}
                </li>
            {/each}
        </ul>
        {#if admin_move_error}
            <p class="tint-warning mt-3.5 rounded-lg border px-3.5 py-2.5 text-[12.5px]" data-testid="admin-move-error">
                Apps on this server can still reach Caddy's admin API. Coresmith tries to move it to a private socket
                every hour, and the last try failed: <span class="font-mono wrap-anywhere">{admin_move_error}</span>
            </p>
        {/if}
        {#if ssh.error}
            <p
                class="tint-warning mt-3.5 rounded-lg border px-3.5 py-2.5 text-[12.5px]"
                data-testid="ssh-hardening-error"
            >
                SSH may still accept passwords on this server. Coresmith tries to turn them off every hour, and the last
                try found: <span class="font-mono wrap-anywhere">{ssh.error}</span>
            </p>
        {:else if !ssh.verified && !ssh.owner}
            <p class="mt-3.5 text-[12.5px] text-muted-foreground" data-testid="ssh-hardening-unchecked">
                Coresmith hasn't checked this server's SSH settings yet. It checks them within the hour.
            </p>
        {/if}
        <div class="mt-auto pt-3">
            <ToggleRow
                label="Audit logging"
                description="Records changes to sudo rules, SSH settings, users and cron jobs. Takes effect at the next provision."
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
        <p class="mb-3 text-[12.5px] text-muted-foreground" data-testid="firewall-summary">
            {#if firewall.kept_ssh}
                This machine's firewall was already on. Provisioning opened
                <span class="font-mono text-[12px]">{firewall.open.join(', ')}</span> and kept its SSH rules. Changes Coresmith
                applied are listed here.
            {:else}
                Default-deny. Provisioning opened <span class="font-mono text-[12px]">{firewall.open.join(', ')}</span>.
                Changes Coresmith applied are listed here.
            {/if}
        </p>
        <div class="flex flex-col">
            {#each firewall.changes as change (change.id)}
                <div class="flex items-baseline gap-3 border-t border-border py-2.5">
                    <span class="w-13 flex-none font-mono text-[12px] text-muted-foreground">{clock(change.at)}</span>
                    <span class="text-[13.5px]">
                        <span class="font-mono">{change.action} {change.port}</span><span class="text-muted-foreground"
                            >{change.from ? ' from ' : ''}</span
                        ><span class="font-mono">{change.from ?? ''}</span>
                    </span>
                </div>
            {:else}
                <p class="border-t border-border pt-3 text-[12.5px] text-muted-foreground">
                    No changes since provisioning.
                </p>
            {/each}
        </div>
        {#if server.can.manageFirewall}
            <Form
                method="post"
                action={route('servers.firewall.store', server.id)}
                options={{ preserveScroll: true }}
                resetOnSuccess
                class="mt-auto pt-4"
            >
                {#snippet children({ errors, processing })}
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="w-24">
                            <NativeSelect
                                name="action"
                                aria-label="Rule"
                                options={[
                                    { value: 'allow', label: 'Allow' },
                                    { value: 'deny', label: 'Deny' }
                                ]}
                            />
                        </div>
                        <div class="w-24">
                            <Input
                                name="port"
                                type="number"
                                min="1"
                                max="65535"
                                placeholder="Port"
                                aria-label="Port"
                                required
                                aria-invalid={errors.port ? true : undefined}
                            />
                        </div>
                        <div class="min-w-36 flex-1">
                            <Input
                                name="from_ip"
                                placeholder="From IP, optional"
                                aria-label="From IP"
                                aria-invalid={errors.from_ip ? true : undefined}
                            />
                        </div>
                        <Button type="submit" variant="outline" disabled={processing}>Apply</Button>
                    </div>
                    <FieldError message={errors.port ?? errors.from_ip} />
                    <p class="mt-2 text-[12px] text-muted-foreground" data-testid="firewall-ssh-note">
                        {#if server.this_machine}
                            Port <span class="font-mono">{firewall.ssh_port}</span> is how people sign in to this machine,
                            so it can't be denied here.
                        {:else}
                            Port <span class="font-mono">{firewall.ssh_port}</span> is how Coresmith reaches this server,
                            so it can't be denied here.
                        {/if}
                    </p>
                {/snippet}
            </Form>
        {/if}
    </div>

    <div class="flex flex-col rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-1">SSH access</h3>
        <p class="mb-3 text-[12.5px] text-muted-foreground" data-testid="ssh-access-summary">
            {#if ssh.owner}
                Coresmith signs in as root with a key that only works from this machine itself. Password login is
                {ssh.passwords_off ? 'off' : 'on'} in this machine's own SSH settings, which Coresmith leaves to you.
            {:else}
                Coresmith signs in with a key it generated for this server alone.{ssh.verified
                    ? ' Password login is off.'
                    : ''}
            {/if}
        </p>
        {#if ssh.owner && ssh.logins}
            <div
                class="tint-warning mb-3 rounded-xl border px-4.5 py-3 text-[13px]"
                data-testid="ssh-passwords-warning"
            >
                <p class="m-0 font-medium">
                    {ssh.logins.password || ssh.logins.keyboard
                        ? 'Password login is on.'
                        : 'Root can sign in with a password.'}
                </p>
                <p class="m-0 mt-1">
                    Anyone can try passwords against this machine's SSH. {ssh.source
                        ? `${ssh.source} turns it on.`
                        : 'sshd turns it on by default.'}
                </p>
                <ol class="mt-2.5 mb-0 flex list-decimal flex-col gap-1.5 pl-4.5">
                    <li>Check that you can sign in with your SSH key, and keep that session open.</li>
                    <li>
                        Run this on the machine:
                        <pre
                            class="terminal mt-1.5 text-[11px] leading-[1.6] break-all whitespace-pre-wrap"
                            data-testid="ssh-passwords-commands">{commands}</pre>
                    </li>
                    <li>Then check again.</li>
                </ol>
                {#if server.can.update}
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <Button variant="outline" size="sm" disabled={checking} onclick={checkAgain}>Check again</Button
                        >
                        {#if ssh.checked}
                            <span class="text-[12px] text-muted-foreground">Last checked {ssh.checked}</span>
                        {/if}
                    </div>
                {/if}
            </div>
        {/if}
        <div class="font-mono text-[12.5px] leading-[1.9] text-muted-foreground">
            <div class="text-foreground">{access.ssh ?? 'no address yet'}</div>
            <div class="break-all">host key {access.fingerprint ?? 'not recorded yet'}</div>
        </div>
        <pre
            class="terminal mt-3 max-h-24 text-[11px] leading-[1.6] break-all whitespace-pre-wrap">{access.public_key}</pre>
    </div>
</div>
