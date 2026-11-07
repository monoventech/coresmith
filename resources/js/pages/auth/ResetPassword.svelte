<script module lang="ts">
    export const layout = {
        title: 'Choose a new password',
        mark: 'password',
        description: 'Once it is saved, every other session on your account is signed out.'
    }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'

    let { token, email }: { token: string; email: string } = $props()
</script>

<AppHead title="Reset password" />

<Form
    method="post"
    action={route('password.update')}
    transform={(data) => ({ ...data, token, email })}
    resetOnSuccess={['password', 'password_confirmation']}
>
    {#snippet children({ errors, processing })}
        <div class="flex flex-col gap-4">
            <div>
                <Label for="email" class="mb-1.5">Email</Label>
                <Input id="email" type="email" name="email" autocomplete="email" value={email} readonly />
                <InputError message={errors.email} />
            </div>
            <div>
                <Label for="password" class="mb-1.5">New password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    aria-invalid={errors.password ? true : undefined}
                />
                <InputError message={errors.password} />
            </div>
            <div>
                <Label for="password_confirmation" class="mb-1.5">Confirm password</Label>
                <PasswordInput id="password_confirmation" name="password_confirmation" autocomplete="new-password" />
                <InputError message={errors.password_confirmation} />
            </div>
            <Button type="submit" class="w-full" disabled={processing} data-test="reset-password-button"
                >Save the new password</Button
            >
        </div>
    {/snippet}
</Form>
