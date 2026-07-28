<script lang="ts">
    import { Form, setLayoutProps } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'

    let showRecoveryInput = $state(false)
    let code = $state('')

    const authConfigContent = $derived.by(() => {
        if (showRecoveryInput) {
            return {
                title: 'Recovery code',
                description: 'Please confirm access to your account by entering one of your emergency recovery codes.',
                buttonText: 'use an authentication code instead'
            }
        }

        return {
            title: 'Authentication code',
            description: 'Enter the authentication code provided by your authenticator application.',
            buttonText: 'use a recovery code instead'
        }
    })

    $effect(() => {
        setLayoutProps({
            title: authConfigContent.title,
            description: authConfigContent.description
        })
    })

    function toggleRecoveryMode(clearErrors: () => void) {
        showRecoveryInput = !showRecoveryInput
        clearErrors()
        code = ''
    }
</script>

<AppHead title="Two-factor authentication" />

<div class="space-y-6">
    {#if !showRecoveryInput}
        <Form method="post" action={route('two-factor.login.store')} class="space-y-4" resetOnError onError={() => (code = '')}>
            {#snippet children({ errors, processing, clearErrors })}
                <input type="hidden" name="code" value={code} />
                <div class="flex flex-col items-center justify-center space-y-3 text-center">
                    <div class="flex w-full items-center justify-center">
                        <InputOTP bind:value={code} maxlength={6} disabled={processing} autofocus>
                            {#snippet children({ cells })}
                                <InputOTPGroup>
                                    {#each cells as cell, index (index)}
                                        <InputOTPSlot {cell} />
                                    {/each}
                                </InputOTPGroup>
                            {/snippet}
                        </InputOTP>
                    </div>
                    <InputError message={errors.code} />
                </div>
                <Button type="submit" class="w-full" disabled={processing}>Continue</Button>
                <div class="text-center text-sm text-muted-foreground">
                    <span>or you can </span>
                    <button
                        type="button"
                        class="rounded-sm text-primary underline decoration-primary/40 underline-offset-4 transition-colors outline-none hover:decoration-current focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                        onclick={() => toggleRecoveryMode(clearErrors)}
                    >
                        {authConfigContent.buttonText}
                    </button>
                </div>
            {/snippet}
        </Form>
    {:else}
        <Form method="post" action={route('two-factor.login.store')} class="space-y-4" resetOnError>
            {#snippet children({ errors, processing, clearErrors })}
                <Input name="recovery_code" type="text" placeholder="Enter recovery code" autofocus required />
                <InputError message={errors.recovery_code} />
                <Button type="submit" class="w-full" disabled={processing}>Continue</Button>

                <div class="text-center text-sm text-muted-foreground">
                    <span>or you can </span>
                    <button
                        type="button"
                        class="rounded-sm text-primary underline decoration-primary/40 underline-offset-4 transition-colors outline-none hover:decoration-current focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                        onclick={() => toggleRecoveryMode(clearErrors)}
                    >
                        {authConfigContent.buttonText}
                    </button>
                </div>
            {/snippet}
        </Form>
    {/if}
</div>
