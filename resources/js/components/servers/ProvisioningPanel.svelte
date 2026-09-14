<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import PhaseIcon from '@/components/PhaseIcon.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import type { ServerHeader } from '@/lib/servers'
    import { cn } from '@/lib/utils'

    let { server }: { server: ServerHeader } = $props()

    const provisioning = $derived(server.provisioning!)
    const running = $derived(provisioning.status === 'provisioning')
    const failed = $derived(provisioning.status === 'failed')
    const pending = $derived(provisioning.status === 'pending' || provisioning.status === 'disconnected')

    let copied = $state(false)

    async function copyKey() {
        await navigator.clipboard.writeText(provisioning.public_key)
        copied = true
        setTimeout(() => (copied = false), 2000)
    }

    function post(name: 'servers.test-connection' | 'servers.provision') {
        router.post(route(name, server.id), {}, { preserveScroll: true })
    }
</script>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card p-6">
        <h3 class="type-h3 m-0 mb-1">Connect {server.name}</h3>
        <p class="mb-4 text-[12.5px] text-muted-foreground">
            Add this key to <span class="font-mono text-[12px]">~/.ssh/authorized_keys</span> for
            <span class="font-mono text-[12px]">{provisioning.ssh_user}</span>, then test the connection. Coresmith generated it for this server alone.
        </p>
        <pre class="terminal max-h-28 text-[11.5px] leading-[1.7] break-all whitespace-pre-wrap" data-testid="public-key">{provisioning.public_key}</pre>
        <p class="mt-2 font-mono text-[12px] text-muted-foreground">{provisioning.ssh}</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <Button variant="outline" size="sm" onclick={copyKey}>{copied ? 'Copied' : 'Copy the key'}</Button>
            {#if server.can.update}
                <Button variant="outline" size="sm" onclick={() => post('servers.test-connection')}>Test connection</Button>
                <Button size="sm" disabled={running} onclick={() => post('servers.provision')}>{failed ? 'Retry provisioning' : 'Provision server'}</Button>
            {/if}
        </div>
    </div>

    <div class="rounded-xl border border-border bg-card p-6" data-testid="provisioning-phases">
        <div class="mb-1.5 flex flex-wrap items-center gap-3">
            <h3 class="type-h3 m-0">{pending ? 'What happens next' : `Provisioning ${server.name}`}</h3>
            {#if running}
                <Badge variant="accent" dot pulse>Phase {Math.min(8, provisioning.completed + 1)} of 8</Badge>
            {:else if failed}
                <Badge variant="destructive" dot>Failed at phase {provisioning.completed + 1}</Badge>
            {/if}
        </div>
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            {pending ? 'Eight phases, hardened by default, not opt-in.' : 'Every phase is logged. Close this page, it keeps going.'}
        </p>
        <div class="flex flex-col gap-0.5">
            {#each provisioning.phases as phase, index (phase.name)}
                <div class={cn('flex items-center gap-3 py-1.5', !pending && phase.state === 'pending' && 'opacity-55')}>
                    {#if pending}
                        <span class="w-4 flex-none font-mono text-[12px] text-primary">{index + 1}</span>
                    {:else}
                        <PhaseIcon state={phase.state} />
                    {/if}
                    <span class="text-[13.5px]"><span class="font-medium">{phase.name}</span><span class="text-muted-foreground">, {phase.desc}</span></span>
                </div>
            {/each}
        </div>
    </div>
</div>

{#if provisioning.log}
    <div class="mt-4 rounded-xl border border-border bg-card px-[22px] py-5">
        <h3 class="type-h3 m-0 mb-2.5">Provisioning log</h3>
        <pre class="terminal max-h-80 whitespace-pre-wrap">{provisioning.log}</pre>
    </div>
{/if}
