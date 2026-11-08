<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import * as RadioGroup from '@/components/ui/radio-group'
    import type { GitHubRegistration } from '@/lib/github'
    import { APP_NAME_PATTERN, manifestFor, ORGANIZATION_PATTERN, organizationAction } from '@/lib/github'
    import { GitBranch } from '@lucide/svelte'
    import type { Snippet } from 'svelte'

    let {
        registration,
        compact = false,
        submitLabel = 'Create on GitHub',
        actions
    }: { registration: GitHubRegistration; compact?: boolean; submitLabel?: string; actions?: Snippet } = $props()

    let owner = $state('personal')
    let organization = $state('')
    let edited = $state<string | null>(null)
    let anyway = $state(false)
    let organizationTouched = $state(false)

    const name = $derived(edited ?? registration.name)
    const reachability = $derived(registration.reachability)
    const host = $derived(reachability.host === '' ? 'Coresmith' : reachability.host)
    const organizationValid = $derived(ORGANIZATION_PATTERN.test(organization))
    const nameValid = $derived(APP_NAME_PATTERN.test(name))
    const organizationError = $derived(
        owner === 'organization' && organizationTouched && !organizationValid
            ? "That isn't a GitHub organization name. Use letters, numbers and single hyphens."
            : null
    )
    const nameError = $derived(
        edited !== null && !nameValid ? 'Use letters, numbers, spaces and hyphens, up to 34 characters.' : null
    )
    const ready = $derived(nameValid && (owner === 'personal' || organizationValid))
    const action = $derived(
        owner === 'organization' ? organizationAction(registration, organization) : registration.personal_action
    )
    const manifest = $derived(manifestFor(registration, name, reachability.reachable || anyway))
    const delivery = $derived(
        reachability.reachable
            ? `GitHub will send each push to ${host} as it happens.`
            : `${reachability.sentence ?? ''} Apps with auto deploy check their branch every minute instead.`.trim()
    )
</script>

<div
    class={compact ? undefined : 'rounded-xl border border-border bg-card px-5.5 py-5'}
    data-testid="github-registration"
>
    {#if !compact}
        <div class="mb-1 flex flex-wrap items-center gap-2.5">
            <GitBranch class="size-4.5 text-muted-foreground" />
            <h3 class="type-h3 m-0">Create the GitHub App</h3>
        </div>
        <p class="mb-4 text-[12.5px] text-muted-foreground">
            Coresmith has no GitHub App yet. GitHub creates one that belongs to you, with Coresmith's addresses filled
            in, and Coresmith keeps its key encrypted here. It asks only for contents read-only, commit statuses and
            webhooks.
        </p>
    {/if}

    <form method="post" {action} class="flex flex-col gap-4">
        <input type="hidden" name="manifest" value={manifest} />

        <div>
            <Label id="github-owner-label" class="mb-1.5">Owner</Label>
            <RadioGroup.Root
                bind:value={owner}
                aria-labelledby="github-owner-label"
                class="gap-0 overflow-hidden rounded-lg border border-border"
            >
                <label
                    class="flex cursor-pointer items-center gap-3 border-b border-border px-3.5 py-2.5 hover:bg-muted max-sm:min-h-11"
                >
                    <RadioGroup.Item value="personal" aria-labelledby="github-owner-personal" />
                    <span id="github-owner-personal" class="text-[13.5px]">Your personal account</span>
                </label>
                <label class="flex cursor-pointer items-center gap-3 px-3.5 py-2.5 hover:bg-muted max-sm:min-h-11">
                    <RadioGroup.Item value="organization" aria-labelledby="github-owner-organization" />
                    <span id="github-owner-organization" class="text-[13.5px]">An organization</span>
                </label>
            </RadioGroup.Root>
        </div>

        {#if owner === 'organization'}
            <div>
                <Label for="github-organization" class="mb-1.5">Organization</Label>
                <Input
                    id="github-organization"
                    bind:value={organization}
                    oninput={() => (organizationTouched = true)}
                    placeholder="acme"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    class="font-mono text-[13px]"
                    aria-invalid={organizationError ? true : undefined}
                />
                <FieldError message={organizationError} />
            </div>
        {/if}

        {#if !compact}
            <div>
                <Label for="github-app-name" class="mb-1.5">Name on GitHub</Label>
                <Input
                    id="github-app-name"
                    value={name}
                    oninput={(event) => (edited = event.currentTarget.value)}
                    autocomplete="off"
                    spellcheck="false"
                    aria-invalid={nameError ? true : undefined}
                    aria-describedby="github-app-name-hint"
                />
                <FieldError message={nameError} />
                <p id="github-app-name-hint" class="mt-1.5 mb-0 text-[12px] text-muted-foreground">
                    App names are unique across GitHub. If this one is taken, GitHub asks for another.
                </p>
            </div>

            <p class="m-0 text-[12.5px] text-muted-foreground">{delivery}</p>

            {#if !reachability.reachable}
                <div class="flex items-center gap-2.5">
                    <Checkbox id="github-webhook-anyway" bind:checked={anyway} />
                    <Label for="github-webhook-anyway" class="font-normal"
                        >Register a webhook anyway, {host} is reachable from the internet</Label
                    >
                </div>
            {/if}
        {/if}

        <div class={actions ? 'flex flex-wrap items-center gap-3' : undefined}>
            <Button type="submit" size={compact ? 'default' : 'sm'} disabled={!ready}>{submitLabel}</Button>
            {@render actions?.()}
        </div>
    </form>
</div>
