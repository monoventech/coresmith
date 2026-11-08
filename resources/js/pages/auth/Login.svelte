<script module lang="ts">
    export const layout = {
        title: 'Sign in to Coresmith',
        mark: 'panel',
        description: 'Use the email and password you set up for this Coresmith installation.'
    }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'

    let {
        status = '',
        canResetPassword,
        canRegister,
        sso = { team: null, start: null, lookup: false, link: null }
    }: {
        status?: string
        canResetPassword: boolean
        canRegister: boolean
        sso?: { team: string | null; start: string | null; lookup: boolean; link: string | null }
    } = $props()
</script>

<AppHead title="Sign in" />

{#if status}
    <p class="tint-success mb-4 rounded-md border px-3.5 py-2.5 text-[13px]">{status}</p>
{/if}

{#if sso.link}
    <p class="tint-primary mb-4 rounded-md border px-3.5 py-2.5 text-[13px]" data-testid="sso-link-notice">
        Coresmith already has an account for {sso.link}. Sign in with its password once to connect single sign-on.
    </p>
{:else if sso.start}
    <div class="mb-4 flex flex-col gap-4" data-testid="sso-entry">
        <Button href={sso.start} variant="outline" class="w-full" data-test="sso-button"
            >Continue with single sign-on</Button
        >
        <p class="m-0 flex items-center gap-3 text-[12px] text-muted-foreground">
            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
            or sign in with your password
            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
        </p>
    </div>
{/if}

<Form method="post" action={route('login')} resetOnSuccess={['password']}>
    {#snippet children({ errors, processing })}
        <div class="flex flex-col gap-4">
            <div>
                <Label for="email" class="mb-1.5">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    value={sso.link ?? ''}
                    required
                    autocomplete="email"
                    placeholder="you@example.com"
                    aria-invalid={errors.email ? true : undefined}
                />
                <InputError message={errors.email} />
            </div>

            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <Label for="password">Password</Label>
                    {#if canResetPassword}
                        <TextLink href={route('password.request')} class="text-[12.5px]">Forgot it?</TextLink>
                    {/if}
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    aria-invalid={errors.password ? true : undefined}
                />
                <InputError message={errors.password} />
            </div>

            <Label for="remember" class="font-normal">
                <Checkbox id="remember" name="remember" />
                <span>Keep me signed in on this device</span>
            </Label>

            <Button type="submit" class="w-full" disabled={processing} data-test="login-button">Sign in</Button>

            {#if !sso.start && sso.lookup}
                <p class="m-0 text-[13px] text-muted-foreground">
                    Signing in through your company? <TextLink
                        href={route('sso')}
                        class="whitespace-nowrap"
                        data-test="sso-lookup-link">Use single sign-on</TextLink
                    >
                </p>
            {/if}

            {#if canRegister}
                <p class="m-0 text-[13px] text-muted-foreground">
                    New here? <TextLink href={route('register')}>Create an account</TextLink>
                </p>
            {/if}
        </div>
    {/snippet}
</Form>
