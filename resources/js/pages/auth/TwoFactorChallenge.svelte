<script lang="ts">
    import { Form, setLayoutProps } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'

    let showRecoveryInput = $state(false)
    let code = $state('')

    const content = $derived(
        showRecoveryInput
            ? {
                  title: 'Use a recovery code',
                  mark: 'recovery',
                  description:
                      'Enter one of the recovery codes you saved when you turned on two-factor authentication.',
                  toggle: 'Use an authenticator code instead'
              }
            : {
                  title: 'Enter your code',
                  mark: 'code',
                  description: 'Open your authenticator app and type the six-digit code for Coresmith.',
                  toggle: 'Use a recovery code instead'
              }
    )

    $effect(() => {
        setLayoutProps({ title: content.title, mark: content.mark, description: content.description })
    })

    function toggleRecoveryMode(clearErrors: () => void) {
        showRecoveryInput = !showRecoveryInput
        clearErrors()
        code = ''
    }
</script>

<AppHead title="Two-factor authentication" />

{#if !showRecoveryInput}
    <Form method="post" action={route('two-factor.login.store')} resetOnError onError={() => (code = '')}>
        {#snippet children({ errors, processing, clearErrors })}
            <input type="hidden" name="code" value={code} />
            <div class="flex flex-col gap-4">
                <div>
                    <InputOTP bind:value={code} maxlength={6} disabled={processing} autofocus>
                        {#snippet children({ cells })}
                            <InputOTPGroup>
                                {#each cells as cell, index (index)}
                                    <InputOTPSlot {cell} />
                                {/each}
                            </InputOTPGroup>
                        {/snippet}
                    </InputOTP>
                    <InputError message={errors.code} />
                </div>
                <Button type="submit" class="w-full" disabled={processing}>Continue</Button>
                <button
                    type="button"
                    class="focus-ring cursor-pointer self-start rounded-sm bg-transparent p-0 text-[13px] font-medium text-primary-strong"
                    onclick={() => toggleRecoveryMode(clearErrors)}
                >
                    {content.toggle}
                </button>
            </div>
        {/snippet}
    </Form>
{:else}
    <Form method="post" action={route('two-factor.login.store')} resetOnError>
        {#snippet children({ errors, processing, clearErrors })}
            <div class="flex flex-col gap-4">
                <div>
                    <Input
                        name="recovery_code"
                        type="text"
                        placeholder="abcd-efgh"
                        autofocus
                        required
                        aria-label="Recovery code"
                        class="font-mono"
                    />
                    <InputError message={errors.recovery_code} />
                </div>
                <Button type="submit" class="w-full" disabled={processing}>Continue</Button>
                <button
                    type="button"
                    class="focus-ring cursor-pointer self-start rounded-sm bg-transparent p-0 text-[13px] font-medium text-primary-strong"
                    onclick={() => toggleRecoveryMode(clearErrors)}
                >
                    {content.toggle}
                </button>
            </div>
        {/snippet}
    </Form>
{/if}
