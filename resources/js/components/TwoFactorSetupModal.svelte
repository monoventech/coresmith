<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'
    import { Spinner } from '@/components/ui/spinner'
    import { twoFactorAuthState } from '@/lib/twoFactorAuth.svelte'
    import { Form } from '@inertiajs/svelte'
    import { tick } from 'svelte'

    type FormErrors = Record<string, string>

    let {
        isOpen = $bindable(false),
        requiresConfirmation,
        twoFactorEnabled
    }: {
        isOpen?: boolean
        requiresConfirmation: boolean
        twoFactorEnabled: boolean
    } = $props()

    const twoFactorAuth = twoFactorAuthState()

    let verifying = $state(false)
    let code = $state('')
    let copied = $state(false)
    let otp = $state<HTMLDivElement>()

    const title = $derived(
        twoFactorEnabled
            ? 'Two-factor authentication is on'
            : verifying
              ? 'Enter the code from your app'
              : 'Turn on two-factor authentication'
    )

    const description = $derived(
        twoFactorEnabled
            ? 'Sign-ins now ask for a code from your authenticator app as well as your password.'
            : verifying
              ? 'Type the six-digit code your authenticator app shows for this panel.'
              : 'Scan the code with an authenticator app, or type the setup key in by hand.'
    )

    async function next() {
        if (requiresConfirmation && !twoFactorEnabled) {
            verifying = true
            await tick()
            otp?.querySelector('input')?.focus()

            return
        }

        twoFactorAuth.clearSetupData()
        isOpen = false
    }

    async function copyKey() {
        const key = twoFactorAuth.state.manualSetupKey

        if (!key) {
            return
        }

        try {
            await navigator.clipboard.writeText(key)
            copied = true
            setTimeout(() => (copied = false), 2000)
        } catch {
            copied = false
        }
    }

    $effect(() => {
        if (!isOpen) {
            if (twoFactorEnabled) {
                twoFactorAuth.clearSetupData()
            }

            verifying = false
            code = ''

            return
        }

        if (!twoFactorAuth.state.qrCodeSvg) {
            void twoFactorAuth.fetchSetupData()
        }
    })
</script>

<Dialog.Root bind:open={isOpen}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>{title}</Dialog.Title>
            <Dialog.Description>{description}</Dialog.Description>
        </Dialog.Header>

        {#if twoFactorAuth.state.errors.length > 0}
            <p class="tint-destructive mt-4 rounded-md border px-3.5 py-2.5 text-[13px]" role="alert">
                The setup details couldn't be loaded. Close this and try again.
            </p>
            <Dialog.Footer>
                <Button variant="ghost" onclick={() => (isOpen = false)}>Close</Button>
            </Dialog.Footer>
        {:else if !verifying}
            <div class="mt-4 flex flex-col items-center gap-4">
                <!-- monoven-allow: a QR code needs true white behind true black or scanners fail -->
                <div
                    class="flex size-44 items-center justify-center rounded-md bg-white p-2.5 [&_svg]:size-full"
                    data-testid="two-factor-qr"
                >
                    {#if twoFactorAuth.state.qrCodeSvg}
                        <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                        {@html twoFactorAuth.state.qrCodeSvg}
                    {:else}
                        <Spinner class="size-5 text-muted-foreground" />
                    {/if}
                </div>
                <div class="flex w-full items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 font-mono text-[12.5px]"
                        data-testid="two-factor-key"
                    >
                        {twoFactorAuth.state.manualSetupKey ?? 'Loading the setup key…'}
                    </code>
                    <Button variant="outline" size="sm" disabled={!twoFactorAuth.state.manualSetupKey} onclick={copyKey}
                        >{copied ? 'Copied' : 'Copy'}</Button
                    >
                </div>
            </div>
            <Dialog.Footer>
                {#if !twoFactorEnabled}
                    <Button variant="ghost" onclick={() => (isOpen = false)}>Cancel</Button>
                {/if}
                <Button disabled={!twoFactorAuth.hasSetupData()} onclick={next}
                    >{twoFactorEnabled ? 'Done' : 'Continue'}</Button
                >
            </Dialog.Footer>
        {:else}
            <Form
                method="post"
                action={route('two-factor.confirm')}
                resetOnError
                onFinish={() => (code = '')}
                onSuccess={() => (isOpen = false)}
            >
                {#snippet children({ errors, processing }: { errors: FormErrors; processing: boolean })}
                    <input type="hidden" name="code" value={code} />
                    <div bind:this={otp} class="mt-4 flex flex-col items-center gap-2">
                        <InputOTP bind:value={code} maxlength={6} disabled={processing} autofocus>
                            {#snippet children({ cells })}
                                <InputOTPGroup>
                                    {#each cells as cell, index (index)}
                                        <InputOTPSlot {cell} />
                                    {/each}
                                </InputOTPGroup>
                            {/snippet}
                        </InputOTP>
                        <FieldError message={errors['confirmTwoFactorAuthentication.code'] ?? errors.code} />
                    </div>
                    <Dialog.Footer>
                        <Button type="button" variant="ghost" disabled={processing} onclick={() => (verifying = false)}
                            >Back</Button
                        >
                        <Button type="submit" disabled={processing || code.length < 6}>Turn it on</Button>
                    </Dialog.Footer>
                {/snippet}
            </Form>
        {/if}
    </Dialog.Content>
</Dialog.Root>
