<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import { CreditCard, Download } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import ChangePlanDialog from '@/components/settings/ChangePlanDialog.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import type { BillingPage } from '@/lib/billing'
    import { cn } from '@/lib/utils'

    const POLLS = 20

    const POLLED = [
        'plan',
        'payment',
        'portal',
        'payment_note',
        'invoices',
        'invoice_count',
        'invoices_note',
        'receipts_to',
        'checkout',
        'plans',
        'self_hosting'
    ]

    let {
        can_manage,
        plan,
        payment,
        portal = false,
        payment_note,
        invoices = [],
        invoice_count = 0,
        invoices_note = null,
        receipts_to = null,
        plans = [],
        self_hosting,
        move = null,
        checkout = null
    }: BillingPage = $props()

    let dialogOpen = $state(false)
    let selection = $state('')
    let working = $state(false)
    let timedOut = $state(false)

    const primaryLabel = $derived(
        plan.primary === 'change' ? 'Change plan' : plan.primary === 'choose' ? 'Choose a plan' : 'Keep my plan'
    )
    const awaiting = $derived(checkout?.awaiting === true)

    $effect(() => {
        if (!awaiting) {
            return
        }

        let polls = 0
        const timer = setInterval(() => {
            polls += 1
            router.reload({ only: POLLED })

            if (polls >= POLLS) {
                clearInterval(timer)
                timedOut = true
            }
        }, 3000)

        return () => clearInterval(timer)
    })

    function openDialog(choice: string) {
        selection = choice
        dialogOpen = true
    }

    function primary() {
        if (plan.primary === 'keep') {
            post(route('settings.billing.plan.resume'))

            return
        }

        openDialog(plans.find((option) => option.current)?.key ?? '')
    }

    function post(url: string) {
        router.post(
            url,
            {},
            { preserveScroll: true, onStart: () => (working = true), onFinish: () => (working = false) }
        )
    }

    function cancelMove() {
        router.delete(route('settings.billing.move.destroy'), {
            preserveScroll: true,
            onStart: () => (working = true),
            onFinish: () => (working = false)
        })
    }
</script>

<AppHead title="Billing" />

{#if checkout}
    <p
        class={cn(
            'mb-4 rounded-xl border px-4.5 py-3 text-[13.5px]',
            checkout.status === 'success' ? 'tint-success' : 'border-border bg-muted'
        )}
        role="status"
        data-testid="checkout-status"
    >
        {awaiting && timedOut ? checkout.timeout_message : checkout.message}
    </p>
{/if}

{#if move}
    <div class="mb-4 rounded-xl border border-border bg-card px-5.5 py-5" data-testid="move-card">
        <h3 class="type-h3 m-0">Moving to your own panel</h3>
        <p class="mt-1 mb-0 text-[12.5px] text-muted-foreground">
            Since {move.since}, Coresmith Cloud has stopped managing this team's servers, and they keep running. Upload
            the move file on {move.to} to finish, or cancel the move to keep the team here.
        </p>
        {#if move.file || move.can_cancel}
            <div class="mt-4 flex flex-wrap gap-2.5">
                {#if move.file}
                    <Button size="sm" href={move.file} download>Download move file</Button>
                {/if}
                {#if move.can_cancel}
                    <Button variant="ghost" size="sm" disabled={working} onclick={cancelMove}>Cancel the move</Button>
                {/if}
            </div>
        {/if}
    </div>
{/if}

<div class="grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <div class="flex h-full flex-col gap-4">
        <div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="plan-card">
            <div class="mb-0.5 flex items-baseline gap-2.5">
                <h3 class="type-h3 m-0">{plan.label}</h3>
                <Badge variant={plan.badge.tone}>{plan.badge.label}</Badge>
            </div>
            <p class="mb-4 text-[12.5px] text-muted-foreground">{plan.line}</p>
            <div class="flex items-baseline gap-2">
                <span class="font-mono text-[32px] font-medium">{plan.amount}</span>
                <span class="text-[13px] text-muted-foreground">/ month · {plan.usage}</span>
            </div>
            {#if plan.note}
                <p class="mt-3 mb-0 text-[12.5px] text-muted-foreground">{plan.note}</p>
            {/if}
            {#if can_manage}
                <div class="mt-4 flex flex-wrap gap-2.5">
                    {#if plan.primary}
                        <Button variant="outline" size="sm" disabled={working} onclick={primary}>{primaryLabel}</Button>
                    {/if}
                    {#if plan.self_host}
                        <Button variant="ghost" size="sm" onclick={() => openDialog('self-hosted')}
                            >Move to self-host</Button
                        >
                    {/if}
                </div>
            {:else}
                <p class="mt-4 mb-0 text-[12.5px] text-muted-foreground">Only an owner can change the plan.</p>
            {/if}
        </div>
        <div class="flex-1 rounded-xl border border-border bg-card px-5.5 py-5" data-testid="payment-card">
            <h3 class="type-h3 m-0 mb-2.5">Payment method</h3>
            <div class="flex flex-wrap items-center gap-3 text-[13.5px]">
                <CreditCard class="size-4.5 shrink-0 text-muted-foreground" />
                {#if payment}
                    <span class="font-mono text-[13px] whitespace-nowrap">{payment.label}</span>
                    {#if payment.expires}
                        <span class="text-[12.5px] whitespace-nowrap text-muted-foreground"
                            >expires {payment.expires}</span
                        >
                    {/if}
                {:else}
                    <span class="min-w-0 flex-1 text-muted-foreground">{payment_note}</span>
                {/if}
                {#if portal}
                    <span class="ml-auto"
                        ><Button
                            variant="ghost"
                            size="sm"
                            disabled={working}
                            onclick={() => post(route('settings.billing.portal'))}>Update</Button
                        ></span
                    >
                {/if}
            </div>
        </div>
    </div>

    <div
        class="flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card"
        data-testid="invoices-card"
    >
        <div class="flex items-baseline gap-2.5 border-b border-border px-5.5 py-4">
            <h3 class="type-h3 m-0">Invoices</h3>
            {#if invoice_count > 0}
                <span class="font-mono text-[12px] text-muted-foreground"
                    >{invoice_count} {invoice_count === 1 ? 'invoice' : 'invoices'}</span
                >
            {/if}
        </div>
        {#if invoices.length > 0}
            <div class="max-h-72 overflow-y-auto">
                {#each invoices as invoice (invoice.id)}
                    <div
                        class="flex min-h-14.5 items-center gap-3.5 border-b border-border px-5.5 py-1 text-[13.5px] max-sm:gap-2.5"
                        data-testid="invoice-row"
                    >
                        <span class="w-27.5 shrink-0 max-sm:w-auto">{invoice.period}</span>
                        <span class="min-w-0 flex-1 truncate font-mono text-[12.5px] text-muted-foreground"
                            >{invoice.number}</span
                        >
                        <span class="w-14 shrink-0 text-right font-mono text-[13px] max-sm:w-auto"
                            >{invoice.amount}</span
                        >
                        <Badge variant={invoice.status.tone}>{invoice.status.label}</Badge>
                        {#if invoice.pdf}
                            <a
                                href={invoice.pdf}
                                target="_blank"
                                rel="noopener noreferrer"
                                class="focus-ring inline-flex size-8 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted max-sm:size-11"
                                aria-label="Download invoice {invoice.number}"
                                title="Download invoice {invoice.number}"
                            >
                                <Download class="size-4" />
                            </a>
                        {/if}
                    </div>
                {/each}
            </div>
        {:else if invoices_note}
            <p class="m-0 flex-1 px-5.5 py-4 text-[13.5px] text-muted-foreground">{invoices_note}</p>
        {/if}
        {#if receipts_to}
            <p class="m-0 mt-auto px-5.5 py-3 text-[12.5px] text-muted-foreground">
                Stripe emails each receipt to {receipts_to}.
            </p>
        {/if}
    </div>
</div>

{#if can_manage}
    <ChangePlanDialog
        bind:open={dialogOpen}
        bind:selected={selection}
        {plans}
        selfHosting={self_hosting}
        choosing={plan.primary === 'choose'}
    />
{/if}
