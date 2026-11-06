<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Form, setLayoutProps } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'

    let { deleted, trial_days: trialDays }: { deleted: boolean; trial_days: number | null } = $props()

    let closing = $state(false)

    $effect(() => {
        setLayoutProps(
            deleted
                ? {
                      title: 'Your team was deleted',
                      mark: 'deleted',
                      description:
                          'Its servers keep running, detached from Coresmith. Start a team to manage servers again, or close your account.'
                  }
                : {
                      title: "You're not on a team",
                      mark: 'team',
                      description: 'Start a team to manage servers, or close your account.'
                  }
        )
    })
</script>

<AppHead title={deleted ? 'Team deleted' : 'No team'} />

<p class="mb-5 text-[13px] text-muted-foreground" data-testid="trial-note">
    {trialDays === null
        ? 'This address already had a trial, so a new team starts without one. Choose a plan from Billing to add servers.'
        : `A new team starts on a ${trialDays}-day trial of Pro with one server. No card needed.`}
</p>

<div class="flex flex-wrap items-center gap-3">
    <Form method="post" action={route('start.store')}>
        {#snippet children({ processing })}
            <Button type="submit" disabled={processing}>Start a team</Button>
        {/snippet}
    </Form>
    <Button variant="outline" class="text-destructive-strong" onclick={() => (closing = true)}
        >Close your account</Button
    >
</div>

<div class="mt-5 border-t border-border pt-4">
    <TextLink
        href={route('logout')}
        method="post"
        as="button"
        class="text-[13px] text-muted-foreground hover:text-foreground">Sign out</TextLink
    >
</div>

<Dialog.Root open={closing} onOpenChange={(open) => (closing = open)}>
    <Dialog.Content>
        <Form method="delete" action={route('profile.destroy')} resetOnError>
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Close your account?</Dialog.Title>
                    <Dialog.Description>This can't be undone. Type your password to confirm.</Dialog.Description>
                </Dialog.Header>
                <div class="mt-4">
                    <Input
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        aria-label="Password"
                        required
                        aria-invalid={errors.password ? true : undefined}
                    />
                    <FieldError message={errors.password} />
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (closing = false)}>Keep my account</Button>
                    <Button variant="destructive" type="submit" disabled={processing}>Close it</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>
