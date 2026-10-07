<script lang="ts">
    import { Link, router } from '@inertiajs/svelte'
    import { GitBranch, Timer } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'

    type Provider = {
        id: string
        type: string
        label: string
        auth_description: string
        name: string
        scopes: string[]
        custom_host: string | null
        management_url: string | null
        connected: boolean
        expires_at: string | null
        connected_at: string | null
    }

    type Connectable = { type: string; label: string; scopes: string[]; configured: boolean }
    type DeployKey = { id: string; name: string; fingerprint: string | null; repository: string | null; href: string }
    type Delivery = { id: string | null; event: string | null; status: number | null; verified: boolean; when: string | null }

    let {
        providers = [],
        connectable = [],
        deployKeys = [],
        webhooks,
        can
    }: {
        providers: Provider[]
        connectable: Connectable[]
        deployKeys: DeployKey[]
        webhooks: { count: number; last: Delivery | null }
        can: { manage: boolean }
    } = $props()

    let disconnecting = $state<Provider | null>(null)
    let working = $state(false)

    function expiry(iso: string | null): string {
        if (!iso) {
            return 'This connection does not expire.'
        }

        const minutes = Math.floor((new Date(iso).getTime() - Date.now()) / 60_000)

        if (minutes <= 0) {
            return 'The token refreshes on its next use.'
        }

        if (minutes < 90) {
            return `Token expires in ${minutes} minutes, rotated automatically.`
        }

        return `Token expires in ${Math.round(minutes / 60)} hours, rotated automatically.`
    }

    function short(fingerprint: string | null): string {
        if (!fingerprint || fingerprint.length <= 20) {
            return fingerprint ?? ''
        }

        return `${fingerprint.slice(0, 11)}…${fingerprint.slice(-4)}`
    }

    function disconnect() {
        if (!disconnecting) {
            return
        }

        working = true
        router.delete(route('source-providers.destroy', disconnecting.id), {
            preserveScroll: true,
            onFinish: () => ((working = false), (disconnecting = null))
        })
    }
</script>

<AppHead title="Git" />

<div class="grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <div class="flex h-full flex-col gap-4">
        {#each providers as provider (provider.id)}
            <div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="provider-card">
                <div class="mb-1 flex flex-wrap items-center gap-2.5">
                    <GitBranch class="size-4.5 text-muted-foreground" />
                    <h3 class="type-h3 m-0">{provider.label}</h3>
                    <Badge variant={provider.connected ? 'success' : 'destructive'}>{provider.connected ? (provider.type === 'github_app' ? 'Installed' : 'Connected') : 'Disconnected'}</Badge>
                </div>
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    {provider.type === 'github_app' ? 'Installed on' : 'Connected as'} <span class="font-mono text-[12px]">{provider.name}</span> · {provider.scopes.join(', ')}
                </p>
                {#if provider.connected}
                    <div class="flex items-center gap-3 rounded-md bg-muted px-3.5 py-2.5 text-[12.5px]">
                        <Timer class="size-4 shrink-0 text-muted-foreground" />
                        {expiry(provider.expires_at)}
                    </div>
                {/if}
                {#if can.manage}
                    <div class="mt-3 flex flex-wrap gap-2">
                        {#if provider.management_url}
                            <Button variant="outline" size="sm" href={provider.management_url} target="_blank" rel="noopener noreferrer">Manage repositories</Button>
                        {/if}
                        <Button variant="ghost" size="sm" class="text-destructive" onclick={() => (disconnecting = provider)}>Disconnect</Button>
                    </div>
                {/if}
            </div>
        {:else}
            <div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="connect-card">
                <h3 class="type-h3 m-0 mb-1">Connect a Git provider</h3>
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Tokens list repositories and register webhooks. Clones always use a separate read-only deploy key per app.
                </p>
                {#each connectable as option (option.type)}
                    <div class="flex min-h-11 flex-wrap items-center gap-3 border-t border-border text-[13px]">
                        <span class="flex-1 font-medium">{option.label}</span>
                        {#if !option.configured}
                            <span class="text-[12px] text-muted-foreground">not configured on this instance</span>
                        {:else if can.manage}
                            <Button variant="outline" size="sm" href={route('source-providers.connect', option.type)}>Connect {option.label}</Button>
                        {:else}
                            <span class="text-[12px] text-muted-foreground">an owner or admin can connect it</span>
                        {/if}
                    </div>
                {/each}
            </div>
        {/each}

        <div class="flex-1 rounded-xl border border-border bg-card px-5.5 py-5" data-testid="webhooks-card">
            <h3 class="type-h3 m-0 mb-1">Webhooks</h3>
            <p class="mb-3 text-[12.5px] text-muted-foreground">HMAC-SHA256 verified · replay protection via delivery ID</p>
            <div class="font-mono text-[12px] text-muted-foreground">
                {#if webhooks.last}
                    last delivery {webhooks.last.id ?? 'unknown'} · {webhooks.last.event ?? 'event'} · {webhooks.last.verified ? 'verified' : 'rejected'} · {webhooks.last.status} · {webhooks.last.when}
                {:else}
                    no deliveries yet · {webhooks.count} active {webhooks.count === 1 ? 'endpoint' : 'endpoints'}
                {/if}
            </div>
        </div>
    </div>

    <div class="flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card" data-testid="deploy-keys-card">
        <div class="px-5.5 pt-5 pb-2">
            <div class="mb-1 flex items-baseline gap-2">
                <h3 class="type-h3 m-0">Deploy keys</h3>
                <span class="font-mono text-[12px] text-muted-foreground">{deployKeys.length} {deployKeys.length === 1 ? 'key' : 'keys'}</span>
            </div>
            <p class="m-0 text-[12.5px] text-muted-foreground">One Ed25519 key per app, read-only. Tokens never touch git.</p>
        </div>
        <div class="max-h-72 flex-1 overflow-y-auto">
            {#each deployKeys as key (key.id)}
                <Link
                    href={key.href}
                    class="focus-ring flex min-h-12 w-full items-center gap-3 border-t border-border px-5.5 py-1.5 text-left text-[13px] text-foreground no-underline transition-colors duration-(--dur-fast) hover:bg-muted"
                >
                    <span class="w-24 shrink-0 truncate font-mono font-medium">{key.name}</span>
                    <span class="min-w-0 flex-1 truncate font-mono text-[12px] text-muted-foreground" title={key.fingerprint ?? undefined}>
                        {short(key.fingerprint)}{#if key.repository}{` · ${key.repository}`}{/if}
                    </span>
                    <Badge variant="outline">read-only</Badge>
                    <span class="text-[12px] text-muted-foreground" aria-hidden="true">→</span>
                </Link>
            {:else}
                <p class="border-t border-border px-5.5 py-3 text-[12.5px] text-muted-foreground">No app uses a deploy key yet. Apps cloned over SSH get one when they're created.</p>
            {/each}
        </div>
        <p class="m-0 border-t border-border px-5.5 py-3 text-[12.5px] text-muted-foreground">
            Keys are created with the app and rotated from it. Open an app to see or replace its key.
        </p>
    </div>
</div>

<Dialog.Root open={disconnecting !== null} onOpenChange={(open) => !open && (disconnecting = null)}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Disconnect {disconnecting?.label}?</Dialog.Title>
            <Dialog.Description>
                Coresmith revokes its token and forgets the connection. Deploy keys stay on each repository, so existing apps keep cloning.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (disconnecting = null)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={disconnect}>Disconnect</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
