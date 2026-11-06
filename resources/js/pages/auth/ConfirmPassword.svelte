<script module lang="ts">
    export const layout = {
        title: 'Confirm your password',
        mark: 'password',
        description: 'This part of the panel changes security settings, so it asks once more.'
    }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import { Button } from '@/components/ui/button'
    import { Label } from '@/components/ui/label'
</script>

<AppHead title="Confirm password" />

<Form method="post" action={route('password.confirm.store')} resetOnSuccess>
    {#snippet children({ errors, processing })}
        <div class="flex flex-col gap-4">
            <div>
                <Label for="password" class="mb-1.5">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    aria-invalid={errors.password ? true : undefined}
                />
                <InputError message={errors.password} />
            </div>
            <Button type="submit" class="w-full" disabled={processing} data-test="confirm-password-button"
                >Confirm</Button
            >
        </div>
    {/snippet}
</Form>
