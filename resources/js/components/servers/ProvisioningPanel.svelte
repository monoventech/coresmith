<script lang="ts">
    import CloudCreationCard from '@/components/cloud/CloudCreationCard.svelte'
    import PhaseIcon from '@/components/PhaseIcon.svelte'
    import AuditReport from '@/components/servers/AuditReport.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { ServerHeader } from '@/lib/servers'
    import { cn } from '@/lib/utils'
    import { router } from '@inertiajs/svelte'

    let { server }: { server: ServerHeader } = $props()

    const provisioning = $derived(server.provisioning!)
    const running = $derived(provisioning.status === 'provisioning')
    const failed = $derived(provisioning.status === 'failed')
    const creating = $derived(provisioning.status === 'creating')
    const pending = $derived(provisioning.status === 'pending' || provisioning.status === 'disconnected' || creating)

    const errorLines = $derived((provisioning.error ?? '').split('\n'))
    const errorSummary = $derived(errorLines[0] ?? '')
    const errorOutput = $derived(errorLines.slice(1).join('\n'))

    let copied = $state(false)
    let restarting = $state(false)

    async function copyKey() {
        await navigator.clipboard.writeText(provisioning.public_key)
        copied = true
        setTimeout(() => (copied = false), 2000)
    }

    function post(name: 'servers.test-connection' | 'servers.provisioning.retry' | 'servers.provisioning.restart') {
        router.post(route(name, server.id), {}, { preserveScroll: true, onFinish: () => (restarting = false) })
    }

    $effect(() => {
        if (!running && provisioning.audit?.state !== 'running' && server.cloud?.task?.state !== 'running') {
            return
        }

        const timer = setInterval(() => router.reload({ only: ['server'] }), 3000)

        return () => clearInterval(timer)
    })
</script>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    {#if server.cloud}
        <CloudCreationCard
            {server}
            cloud={server.cloud}
            onretry={() => post('servers.provisioning.retry')}
            onrestart={() => (restarting = true)}
        />
    {:else}
        <div class="rounded-xl border border-border bg-card p-6">
            <h3 class="type-h3 m-0 mb-1">Connect {server.name}</h3>
            <p class="mb-4 text-[12.5px] text-muted-foreground">
                Add this key to <span class="font-mono text-[12px]">~/.ssh/authorized_keys</span> for
                <span class="font-mono text-[12px]">{provisioning.ssh_user}</span>, then test the connection. Coresmith
                generated it for this server alone.
            </p>
            <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
            <pre
                class="terminal max-h-28 text-[11.5px] leading-[1.7] break-all whitespace-pre-wrap"
                data-testid="public-key"
                tabindex="0"
                role="region"
                aria-label="SSH public key">{provisioning.public_key}</pre>
            {#if provisioning.ssh}
                <p class="mt-2 font-mono text-[12px] text-muted-foreground">{provisioning.ssh}</p>
            {/if}
            <div class="mt-4 flex flex-wrap gap-2">
                <Button variant="outline" size="sm" onclick={copyKey}>{copied ? 'Copied' : 'Copy the key'}</Button>
                {#if server.can.update}
                    <Button variant="outline" size="sm" onclick={() => post('servers.test-connection')}
                        >Test connection</Button
                    >
                    {#if failed}
                        <Button size="sm" onclick={() => post('servers.provisioning.retry')}
                            >{`Retry from phase ${provisioning.failed_phase}`}</Button
                        >
                        <Button variant="outline" size="sm" onclick={() => (restarting = true)}>Start over</Button>
                    {/if}
                {/if}
            </div>
        </div>
    {/if}

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
            {pending
                ? 'Eight phases, hardened by default, not opt-in.'
                : 'Every phase is logged. Close this page, it keeps going.'}
        </p>
        <div class="flex flex-col gap-0.5">
            {#each provisioning.phases as phase, index (phase.name)}
                <div
                    class={cn(
                        'flex items-center gap-3 py-1.5',
                        !pending && phase.state === 'pending' && 'text-muted-foreground'
                    )}
                >
                    {#if pending}
                        <span class="w-4 flex-none font-mono text-[12px] text-primary-strong">{index + 1}</span>
                    {:else}
                        <PhaseIcon state={phase.state} />
                    {/if}
                    <span class="text-[13.5px]"
                        ><span class="font-medium">{phase.name}</span><span class="text-muted-foreground"
                            >, {phase.desc}</span
                        ></span
                    >
                </div>
                {#if phase.state === 'failed' && provisioning.error}
                    <div class="mb-1 ml-7" data-testid="phase-error">
                        <p class="m-0 text-[12.5px] text-destructive-strong">{errorSummary}</p>
                        {#if errorOutput}
                            <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
                            <pre
                                class="terminal mt-1.5 max-h-40 text-[11.5px] whitespace-pre-wrap"
                                tabindex="0"
                                role="region"
                                aria-label="Phase error output">{errorOutput}</pre>
                        {/if}
                    </div>
                {/if}
            {/each}
        </div>
    </div>
</div>

<Dialog.Root bind:open={restarting}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Start over from phase 1?</Dialog.Title>
            <Dialog.Description>
                Every phase runs again. Each phase is written to be safe to run again.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (restarting = false)}>Cancel</Button>
            <Button onclick={() => post('servers.provisioning.restart')}>Start over</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>

{#if pending && !creating}
    <AuditReport {server} />
{/if}

{#if provisioning.log}
    <div class="mt-4 rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-2.5">Provisioning log</h3>
        <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
        <pre
            class="terminal max-h-80 whitespace-pre-wrap"
            tabindex="0"
            role="log"
            aria-live="polite"
            aria-label="Provisioning log">{provisioning.log}</pre>
    </div>
{/if}
