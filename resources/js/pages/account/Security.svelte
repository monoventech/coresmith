<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import ShieldCheck from '@lucide/svelte/icons/shield-check'
    import { onDestroy } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.svelte'
    import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import { Label } from '@/components/ui/label'
    import { twoFactorAuthState } from '@/lib/twoFactorAuth.svelte'

    let {
        canManageTwoFactor = false,
        requiresConfirmation = false,
        twoFactorEnabled = false
    }: {
        canManageTwoFactor?: boolean
        requiresConfirmation?: boolean
        twoFactorEnabled?: boolean
    } = $props()

    const twoFactorAuth = twoFactorAuthState()
    let showSetupModal = $state(false)

    onDestroy(() => twoFactorAuth.clearTwoFactorAuthData())
</script>

<AppHead title="Security" />

<div class="flex max-w-165 flex-col gap-4">
    <div class="rounded-xl border border-border bg-card p-6" data-testid="password-card">
        <h3 class="type-h3 m-0 mb-1">Password</h3>
        <p class="mb-4 text-[12.5px] text-muted-foreground">
            Use a long, random one. A password manager remembers it so you don't have to.
        </p>

        <Form
            method="put"
            action={route('user-password.update')}
            options={{ preserveScroll: true }}
            resetOnSuccess
            resetOnError={['password', 'password_confirmation', 'current_password']}
        >
            {#snippet children({ errors, processing })}
                <div class="flex flex-col gap-4">
                    <div>
                        <Label for="current_password" class="mb-1.5">Current password</Label>
                        <PasswordInput id="current_password" name="current_password" autocomplete="current-password" />
                        <FieldError message={errors.current_password} />
                    </div>
                    <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                        <div>
                            <Label for="password" class="mb-1.5">New password</Label>
                            <PasswordInput id="password" name="password" autocomplete="new-password" />
                            <FieldError message={errors.password} />
                        </div>
                        <div>
                            <Label for="password_confirmation" class="mb-1.5">Confirm password</Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autocomplete="new-password"
                            />
                            <FieldError message={errors.password_confirmation} />
                        </div>
                    </div>
                    <div>
                        <Button type="submit" disabled={processing} data-test="update-password-button"
                            >Save password</Button
                        >
                    </div>
                </div>
            {/snippet}
        </Form>
    </div>

    {#if canManageTwoFactor}
        <div class="rounded-xl border border-border bg-card p-6" data-testid="two-factor-card">
            <div class="mb-1 flex items-center gap-2.5">
                <h3 class="type-h3 m-0">Two-factor authentication</h3>
                <Badge variant={twoFactorEnabled ? 'success' : 'outline'}>{twoFactorEnabled ? 'On' : 'Off'}</Badge>
            </div>

            {#if !twoFactorEnabled}
                <p class="mb-4 text-[12.5px] text-muted-foreground">
                    Sign-ins ask for a code from an authenticator app on your phone as well as your password.
                </p>
                {#if twoFactorAuth.hasSetupData()}
                    <Button onclick={() => (showSetupModal = true)}><ShieldCheck class="size-4" />Continue setup</Button
                    >
                {:else}
                    <Form method="post" action={route('two-factor.enable')} onSuccess={() => (showSetupModal = true)}>
                        {#snippet children({ processing })}
                            <Button type="submit" disabled={processing}>Enable 2FA</Button>
                        {/snippet}
                    </Form>
                {/if}
            {:else}
                <p class="mb-4 text-[12.5px] text-muted-foreground">
                    Sign-ins ask for a code from your authenticator app. Keep the recovery codes somewhere safe.
                </p>
                <div class="flex flex-col items-start gap-4">
                    <Form method="delete" action={route('two-factor.disable')}>
                        {#snippet children({ processing })}
                            <Button
                                variant="outline"
                                class="text-destructive-strong"
                                type="submit"
                                disabled={processing}>Disable 2FA</Button
                            >
                        {/snippet}
                    </Form>
                    <TwoFactorRecoveryCodes />
                </div>
            {/if}

            <TwoFactorSetupModal bind:isOpen={showSetupModal} {requiresConfirmation} {twoFactorEnabled} />
        </div>
    {/if}
</div>
