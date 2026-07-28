<script module lang="ts">
    export const layout = {
        title: 'Forgot password',
        description: 'Tell us your email and we will send a reset link.'
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
    import { Spinner } from '@/components/ui/spinner'

    let {
        status = ''
    }: {
        status?: string
    } = $props()
</script>

<AppHead title="Forgot password" />

{#if status}
    <div class="mb-6 text-sm font-medium text-success">
        {status}
    </div>
{/if}

<div class="space-y-6">
    <Form method="post" action={route('password.email')}>
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input id="email" type="email" name="email" autocomplete="off" placeholder="email@example.com" />
                <InputError message={errors.email} />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button type="submit" class="w-full" disabled={processing} data-test="email-password-reset-link-button">
                    {#if processing}<Spinner />{/if}
                    Send reset link
                </Button>
            </div>
        {/snippet}
    </Form>

    <div class="space-x-1 text-sm text-muted-foreground">
        <span>Or go back to</span>
        <TextLink href={route('login')}>sign in</TextLink>
    </div>
</div>
