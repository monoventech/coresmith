<script module lang="ts">
    export const layout = {
        title: 'Reset your password',
        mark: 'password',
        description: "Tell us the email you sign in with and we'll send a link to choose a new one."
    }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'

    let { status = '' }: { status?: string } = $props()
</script>

<AppHead title="Forgot password" />

{#if status}
    <p class="tint-success mb-4 rounded-md border px-3.5 py-2.5 text-[13px]">{status}</p>
{/if}

<Form method="post" action={route('password.email')}>
    {#snippet children({ errors, processing })}
        <div class="flex flex-col gap-4">
            <div>
                <Label for="email" class="mb-1.5">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    placeholder="you@example.com"
                    aria-invalid={errors.email ? true : undefined}
                />
                <InputError message={errors.email} />
            </div>
            <Button type="submit" class="w-full" disabled={processing} data-test="email-password-reset-link-button"
                >Send the reset link</Button
            >
            <p class="m-0 text-[13px] text-muted-foreground">
                Remembered it? <TextLink href={route('login')}>Sign in</TextLink>
            </p>
        </div>
    {/snippet}
</Form>
