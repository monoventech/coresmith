<script lang="ts">
    import { CreditCard } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'

    let { cloud, servers, team }: { cloud: boolean; servers: number; team: string | null } = $props()
</script>

<AppHead title="Billing" />

<div class="grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <div class="flex h-full flex-col gap-4">
        <div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="plan-card">
            <div class="mb-0.5 flex items-baseline gap-2.5">
                <h3 class="type-h3 m-0">Self-hosted</h3>
                <Badge variant="accent">Active</Badge>
            </div>
            <p class="mb-4 text-[12.5px] text-muted-foreground">Free under ELv2 · every feature included{#if team}{` · ${team}`}{/if}</p>
            <div class="flex items-baseline gap-2">
                <span class="font-mono text-[32px] font-medium">$0</span>
                <span class="text-[13px] text-muted-foreground">/ month · {servers} {servers === 1 ? 'server' : 'servers'}</span>
            </div>
            <div class="mt-4 flex flex-wrap gap-2.5">
                <Button variant="outline" size="sm" href="https://github.com/monoven/coresmith/blob/main/LICENSE" target="_blank" rel="noopener noreferrer">Read the licence</Button>
            </div>
        </div>
        <div class="flex-1 rounded-xl border border-border bg-card px-5.5 py-5">
            <h3 class="type-h3 m-0 mb-2.5">Payment method</h3>
            <div class="flex items-center gap-3 text-[13.5px]">
                <CreditCard class="size-4.5 shrink-0 text-muted-foreground" />
                <span class="text-muted-foreground">No card needed while you self-host.</span>
            </div>
        </div>
    </div>

    <div class="flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card" data-testid="invoices-card">
        <div class="border-b border-border px-5.5 py-4"><h3 class="type-h3 m-0">Invoices</h3></div>
        <div class="flex-1 px-5.5 py-4">
            <p class="m-0 text-[13.5px]">No invoices.</p>
            <p class="mt-1 text-[12.5px] text-muted-foreground">
                {cloud
                    ? 'Billing is switched on for this instance, but invoicing waits on the Coresmith Cloud payment integration.'
                    : "Self-hosting is free, so there's nothing to bill. Invoices only exist on Coresmith Cloud."}
            </p>
        </div>
        <p class="m-0 border-t border-border px-5.5 py-3 text-[12.5px] text-muted-foreground">The self-hosted panel and Coresmith Cloud run the same code, feature for feature.</p>
    </div>
</div>
