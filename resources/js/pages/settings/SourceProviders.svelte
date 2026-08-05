<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'

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

    type Connectable = {
        type: string
        label: string
        scopes: string[]
        configured: boolean
    }

    let {
        providers = [],
        connectable = [],
        can
    }: {
        providers: Provider[]
        connectable: Connectable[]
        can: { manage: boolean }
    } = $props()

    let disconnecting = $state<string | null>(null)

    function since(iso: string | null): string {
        if (!iso) {
            return 'at an unknown time'
        }

        const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
        const months = Math.floor(days / 30)

        if (days < 1) {
            return 'today'
        }

        if (days === 1) {
            return 'yesterday'
        }

        if (days < 30) {
            return `${days} days ago`
        }

        return months === 1 ? 'a month ago' : `${months} months ago`
    }

    function expiry(iso: string | null): string {
        if (!iso) {
            return 'Does not expire'
        }

        const minutes = Math.floor((new Date(iso).getTime() - Date.now()) / 60_000)

        if (minutes <= 0) {
            return 'Refreshes on next use'
        }

        if (minutes < 60) {
            return `Expires in ${minutes}m`
        }

        return `Expires in ${Math.floor(minutes / 60)}h ${minutes % 60}m`
    }

    function disconnect(provider: Provider) {
        disconnecting = provider.id
        router.delete(route('source-providers.destroy', provider.id), {
            preserveScroll: true,
            onFinish: () => (disconnecting = null)
        })
    }
</script>

<AppHead title="Source providers" />

<div class="space-y-8">
    <Heading
        variant="small"
        title="Source providers"
        description="Connected Git accounts. Tokens list repositories and register webhooks — they never clone. Cloning always uses a per-application read-only deploy key."
    />

    {#if providers.length === 0}
        <p class="rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
            No Git account is connected yet. Applications can still deploy from a public https:// URL, or from a private
            repository once you register its deploy key.
        </p>
    {:else}
        <ul class="space-y-4">
            {#each providers as provider (provider.id)}
                <li class="rounded-xl border border-border bg-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold">{provider.label} — {provider.name}</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Connected {since(provider.connected_at)} · {provider.auth_description}
                            </p>
                            {#if provider.custom_host}
                                <p class="mt-1 truncate font-mono text-xs text-muted-foreground">{provider.custom_host}</p>
                            {/if}
                        </div>

                        <div class="text-right">
                            <StatusBadge status={provider.connected ? 'active' : 'disconnected'} />
                            {#if provider.connected}
                                <p class="mt-1 text-xs text-muted-foreground">{expiry(provider.expires_at)}</p>
                            {/if}
                        </div>
                    </div>

                    {#if provider.scopes.length > 0}
                        <p class="mt-3 text-xs text-muted-foreground">Permissions: {provider.scopes.join(', ')}</p>
                    {/if}

                    {#if can.manage}
                        <div class="mt-4 flex flex-wrap gap-2">
                            {#if provider.management_url}
                                <Button variant="outline" size="sm" href={provider.management_url} target="_blank" rel="noopener noreferrer">
                                    Manage on {provider.label}
                                </Button>
                            {/if}
                            <Button variant="destructive" size="sm" disabled={disconnecting === provider.id} onclick={() => disconnect(provider)}>
                                {disconnecting === provider.id ? 'Disconnecting…' : 'Disconnect'}
                            </Button>
                        </div>
                    {/if}
                </li>
            {/each}
        </ul>
    {/if}

    <div class="rounded-xl border border-border bg-card p-5">
        <h3 class="text-sm font-semibold">Available providers</h3>
        <ul class="mt-3 space-y-3">
            {#each connectable as option (option.type)}
                <li class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{option.label}</p>
                        <p class="truncate text-xs text-muted-foreground">{option.scopes.join(', ')}</p>
                    </div>
                    {#if !option.configured}
                        <Badge variant="secondary">Not configured on this instance</Badge>
                    {:else if can.manage}
                        <Button variant="outline" size="sm" href={route('source-providers.connect', option.type)}>
                            Connect {option.label}
                        </Button>
                    {:else}
                        <Badge variant="outline">Ready to connect</Badge>
                    {/if}
                </li>
            {/each}
        </ul>
    </div>
</div>
