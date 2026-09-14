<script module lang="ts">
    export const layout = {
        title: 'Create your account',
        mark: 'account',
        description: 'A name, an email and a long password. The rest happens in the panel.'
    }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
</script>

<AppHead title="Create an account" />

<Form method="post" action={route('register')} resetOnSuccess={['password', 'password_confirmation']}>
    {#snippet children({ errors, processing })}
        <div class="flex flex-col gap-4">
            <div>
                <Label for="name" class="mb-1.5">Name</Label>
                <Input id="name" type="text" required autocomplete="name" name="name" aria-invalid={errors.name ? true : undefined} />
                <InputError message={errors.name} />
            </div>

            <div>
                <Label for="email" class="mb-1.5">Email address</Label>
                <Input id="email" type="email" required autocomplete="email" name="email" placeholder="you@example.com" aria-invalid={errors.email ? true : undefined} />
                <InputError message={errors.email} />
            </div>

            <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                <div>
                    <Label for="password" class="mb-1.5">Password</Label>
                    <PasswordInput id="password" required autocomplete="new-password" name="password" aria-invalid={errors.password ? true : undefined} />
                    <InputError message={errors.password} />
                </div>
                <div>
                    <Label for="password_confirmation" class="mb-1.5">Confirm password</Label>
                    <PasswordInput id="password_confirmation" required autocomplete="new-password" name="password_confirmation" />
                    <InputError message={errors.password_confirmation} />
                </div>
            </div>

            <Button type="submit" class="w-full" disabled={processing} data-test="register-user-button">Create account</Button>

            <p class="m-0 text-[13px] text-muted-foreground">
                Already have one? <TextLink href={route('login')}>Sign in</TextLink>
            </p>
        </div>
    {/snippet}
</Form>
