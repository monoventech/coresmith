<script module lang="ts">
    export const layout = {
        title: 'Sign in',
        description: 'Use your email and password to continue.'
    }
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Spinner } from '@/components/ui/spinner'
    import { Form } from '@inertiajs/svelte'

    let {
        status = '',
        canResetPassword,
        canRegister
    }: {
        status?: string
        canResetPassword: boolean
        canRegister: boolean
    } = $props()
</script>

<AppHead title="Sign in" />

{#if status}
    <div class="mb-6 text-sm font-medium text-success">
        {status}
    </div>
{/if}

<Form method="post" action={route('login')} resetOnSuccess={['password']} class="flex flex-col gap-6">
    {#snippet children({ errors, processing })}
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input id="email" type="email" name="email" required autocomplete="email" placeholder="email@example.com" />
                <InputError message={errors.email} />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    {#if canResetPassword}
                        <TextLink href={route('password.request')} class="text-sm">Forgot password?</TextLink>
                    {/if}
                </div>
                <PasswordInput id="password" name="password" required autocomplete="current-password" placeholder="Password" />
                <InputError message={errors.password} />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button type="submit" class="mt-4 w-full" disabled={processing} data-test="login-button">
                {#if processing}<Spinner />{/if}
                Sign in
            </Button>
        </div>

        {#if canRegister}
            <div class="text-sm text-muted-foreground">
                New here?
                <TextLink href={route('register')}>Create an account</TextLink>
            </div>
        {/if}
    {/snippet}
</Form>
