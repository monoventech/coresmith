<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, router } from '@inertiajs/svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import * as RadioGroup from '@/components/ui/radio-group'
    import type { PlanOption, SelfHosting } from '@/lib/billing'
    import { cn } from '@/lib/utils'

    const SELF_HOSTED = 'self-hosted'

    let {
        open = $bindable(false),
        selected = $bindable(''),
        plans,
        selfHosting,
        choosing
    }: {
        open?: boolean
        selected?: string
        plans: PlanOption[]
        selfHosting: SelfHosting
        choosing: boolean
    } = $props()

    let error = $state<string | undefined>(undefined)
    let processing = $state(false)

    const plan = $derived(plans.find((option) => option.key === selected) ?? null)
    const selfHosted = $derived(selected === SELF_HOSTED)
    const action = $derived(
        selfHosted
            ? selfHosting.has_subscription
                ? 'End Cloud plan'
                : null
            : selfHosting.has_subscription
              ? 'Save'
              : 'Continue to Stripe'
    )
    const blocked = $derived(
        selfHosted ? false : plan === null || plan.current || !plan.available || plan.blockers.length > 0
    )

    $effect(() => {
        if (open) {
            error = undefined
        }
    })

    function choose(value: string) {
        selected = value
        error = undefined
    }

    function submit() {
        const options = {
            preserveScroll: true,
            onStart: () => (processing = true),
            onSuccess: () => (open = false),
            onError: (errors: Record<string, string>) => (error = errors.plan),
            onFinish: () => (processing = false)
        }

        if (selfHosted) {
            router.delete(route('settings.billing.plan.destroy'), options)

            return
        }

        router.put(route('settings.billing.plan.update'), { plan: selected }, options)
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
        <Dialog.Header>
            <Dialog.Title>{choosing ? 'Choose a plan' : 'Change plan'}</Dialog.Title>
            <Dialog.Description>Billed monthly, per team. Self-hosting is free under ELv2.</Dialog.Description>
        </Dialog.Header>

        <RadioGroup.Root value={selected} onValueChange={choose} aria-label="Plans" class="mt-4 gap-2">
            {#each plans as option (option.key)}
                <label
                    class={cn(
                        'flex items-center gap-3 rounded-md border px-3.5 py-3',
                        option.available ? 'cursor-pointer hover:bg-muted' : 'cursor-not-allowed',
                        selected === option.key ? 'option-selected' : 'border-border'
                    )}
                >
                    <RadioGroup.Item
                        value={option.key}
                        disabled={!option.available}
                        aria-labelledby={`plan-${option.key}-label`}
                        aria-describedby={`plan-${option.key}-summary`}
                    />
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span id={`plan-${option.key}-label`} class="text-[14px] font-medium">{option.label}</span>
                        <span id={`plan-${option.key}-summary`} class="text-[12px] text-muted-foreground"
                            >{option.summary}</span
                        >
                    </span>
                    {#if option.current}
                        <span class="text-[12px] font-medium text-primary-strong">current</span>
                    {:else if !option.available}
                        <span class="text-[12px] text-muted-foreground">Not available yet</span>
                    {/if}
                </label>
            {/each}
            <label
                class={cn(
                    'flex cursor-pointer items-center gap-3 rounded-md border px-3.5 py-3 hover:bg-muted',
                    selfHosted ? 'option-selected' : 'border-border'
                )}
            >
                <RadioGroup.Item
                    value={SELF_HOSTED}
                    aria-labelledby="plan-self-hosted-label"
                    aria-describedby="plan-self-hosted-summary"
                />
                <span class="flex min-w-0 flex-1 flex-col">
                    <span id="plan-self-hosted-label" class="text-[14px] font-medium">Self-hosted</span>
                    <span id="plan-self-hosted-summary" class="text-[12px] text-muted-foreground"
                        >Free · every feature · you run the panel</span
                    >
                </span>
            </label>
        </RadioGroup.Root>

        <div aria-live="polite" class="mt-3 flex flex-col gap-2 text-[12.5px]" data-testid="plan-consequences">
            {#if selfHosted}
                <div class="rounded-md border border-border px-3.5 py-3">
                    {#if selfHosting.has_subscription}
                        <p class="m-0">
                            {selfHosting.ends_on
                                ? `Coresmith Cloud stops on ${selfHosting.ends_on}, when this period ends.`
                                : 'Coresmith Cloud stops when this period ends.'}
                            Your servers and apps keep running. A self-hosted panel can't take over servers Coresmith Cloud
                            set up yet, so set your apps up there on fresh servers.
                        </p>
                    {:else}
                        <p class="m-0">
                            There is nothing to cancel.
                            {#if selfHosting.trial_ends_on}The trial ends on its own on {selfHosting.trial_ends_on}.{/if}
                            To run Coresmith yourself, install it on your own server. It can't take over servers Coresmith
                            Cloud set up yet, so add fresh servers for your apps.
                        </p>
                    {/if}
                    <a
                        href={selfHosting.guide}
                        target="_blank"
                        rel="noopener noreferrer"
                        class="focus-ring mt-1.5 inline-block rounded-sm font-medium text-primary-strong no-underline underline-offset-4 hover:underline max-sm:inline-flex max-sm:min-h-11 max-sm:items-center"
                        >Read how to self-host</a
                    >
                </div>
            {:else if plan}
                {#each plan.blockers as blocker (blocker.message)}
                    <div class="tint-destructive rounded-md border px-3.5 py-3" data-testid="plan-blocker">
                        <p class="m-0">{blocker.message}</p>
                        <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1">
                            {#each blocker.links as link (link.href)}
                                <Link
                                    href={link.href}
                                    class="focus-ring rounded-sm font-medium text-primary-strong no-underline underline-offset-4 hover:underline max-sm:inline-flex max-sm:min-h-11 max-sm:items-center"
                                    >{link.label}</Link
                                >
                            {/each}
                        </div>
                    </div>
                {/each}
                {#each plan.notes as note (note)}
                    <p class="m-0 text-muted-foreground">{note}</p>
                {/each}
            {/if}
        </div>
        <FieldError message={error} />

        <Dialog.Footer>
            <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
            {#if action}
                <Button
                    type="button"
                    variant={selfHosted ? 'destructive' : 'default'}
                    disabled={blocked || processing}
                    onclick={submit}>{action}</Button
                >
            {/if}
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
