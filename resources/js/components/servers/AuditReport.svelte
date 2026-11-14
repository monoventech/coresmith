<script lang="ts">
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import type { AuditFinding, ServerHeader } from '@/lib/servers'
    import { router } from '@inertiajs/svelte'

    let { server }: { server: ServerHeader } = $props()

    const audit = $derived(server.provisioning?.audit ?? null)

    const SEVERITY: Record<
        AuditFinding['severity'],
        { label: string; variant: 'destructive' | 'warning' | 'outline' }
    > = {
        blocker: { label: 'Blocker', variant: 'destructive' },
        warning: { label: 'Warning', variant: 'warning' },
        info: { label: 'Info', variant: 'outline' }
    }

    let fixing = $state<AuditFinding | null>(null)
    let busy = $state(false)

    const service = (fix: string | null): string => (fix ?? '').replace(/^stop-/, '')

    function post(url: string) {
        busy = true
        router.post(url, {}, { preserveScroll: true, onFinish: () => ((busy = false), (fixing = null)) })
    }

    const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`
</script>

<div class="mt-4 rounded-xl border border-border bg-card px-5.5 py-5" data-testid="audit-report">
    {#if audit === null}
        <h3 class="type-h3 m-0 mb-1">Before provisioning</h3>
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Coresmith checks the server for anything that would clash, like another web server on port 80, before it
            changes a thing.
        </p>
        {#if server.can.update}
            <Button variant="outline" size="sm" disabled={busy} onclick={() => post(route('servers.audit', server.id))}
                >Check the server</Button
            >
        {/if}
    {:else if audit.state === 'running'}
        <div class="flex flex-wrap items-center gap-2.5">
            <h3 class="type-h3 m-0">Checking {server.name}</h3>
            <Badge variant="accent" dot pulse>Checking</Badge>
        </div>
        <p class="mt-1 mb-0 text-[12.5px] text-muted-foreground">
            Nothing on the server changes while Coresmith looks. This takes a few seconds.
        </p>
    {:else if audit.state === 'failed'}
        <h3 class="type-h3 m-0 mb-2">The check did not finish</h3>
        <p class="tint-destructive m-0 rounded-md border px-3.5 py-2.5 text-[13px]" data-testid="audit-error">
            {audit.error}
        </p>
        {#if server.can.update}
            <div class="mt-3">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onclick={() => post(route('servers.audit', server.id))}>Check again</Button
                >
            </div>
        {/if}
    {:else}
        <div class="mb-1 flex flex-wrap items-center gap-2.5">
            <h3 class="type-h3 m-0">Before provisioning</h3>
            {#if audit.blockers > 0}
                <Badge variant="destructive" dot>{plural(audit.blockers, 'blocker', 'blockers')}</Badge>
            {/if}
            {#if audit.warnings > 0}
                <Badge variant="warning" dot>{plural(audit.warnings, 'warning', 'warnings')}</Badge>
            {/if}
            {#if audit.blockers === 0 && audit.warnings === 0}
                <Badge variant="success" dot>Nothing in the way</Badge>
            {/if}
        </div>
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            {`Checked ${audit.ran ?? 'recently'}. ${audit.blockers > 0 ? 'Resolve the blockers, then check again.' : 'Review anything flagged, then provision.'}`}
        </p>
        <ul class="m-0 list-none p-0">
            {#each audit.findings as finding (finding.key)}
                <li
                    class="flex flex-wrap items-start gap-3 border-t border-border py-3 max-sm:gap-2"
                    data-testid="audit-finding"
                    data-severity={finding.severity}
                >
                    <Badge variant={SEVERITY[finding.severity].variant} class="mt-0.5 min-w-17 justify-center"
                        >{SEVERITY[finding.severity].label}</Badge
                    >
                    <div class="min-w-0 flex-1 max-sm:basis-full">
                        <div class="text-[13.5px] font-medium">{finding.title}</div>
                        <div class="mt-0.5 text-[12.5px] text-muted-foreground">{finding.detail}</div>
                    </div>
                    {#if finding.fix && server.can.update}
                        <Button variant="outline" size="sm" onclick={() => (fixing = finding)}
                            >Stop {service(finding.fix)}</Button
                        >
                    {/if}
                </li>
            {/each}
        </ul>
        {#if server.can.update}
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-4">
                <Button
                    disabled={!audit.can_proceed || busy}
                    onclick={() => post(route('servers.provision', server.id))}>Proceed with provisioning</Button
                >
                <Button variant="ghost" disabled={busy} onclick={() => post(route('servers.audit', server.id))}
                    >Check again</Button
                >
                {#if audit.blockers > 0}
                    <span class="text-[12.5px] text-muted-foreground">Resolve the blockers to continue.</span>
                {:else if !audit.fresh}
                    <span class="text-[12.5px] text-muted-foreground"
                        >The check is over an hour old. Check again first.</span
                    >
                {/if}
            </div>
        {/if}
    {/if}
</div>

<Dialog.Root open={fixing !== null} onOpenChange={(open) => !open && (fixing = null)}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>{`Stop ${service(fixing?.fix ?? null)} on ${server.name}?`}</Dialog.Title>
            <Dialog.Description>
                Coresmith stops and disables it. Any site it serves on this server goes offline, and Coresmith takes
                over ports 80 and 443 when provisioning runs.
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (fixing = null)}>Keep it running</Button>
            <Button
                variant="destructive"
                disabled={busy}
                onclick={() => fixing?.fix && post(route('servers.audit.fix', [server.id, fixing.fix]))}
            >
                {`Stop ${service(fixing?.fix ?? null)}`}
            </Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
