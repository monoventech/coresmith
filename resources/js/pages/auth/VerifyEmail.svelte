<script module lang="ts">
    export const layout = {
        title: 'Verify email',
        description: 'We emailed you a verification link. Open it to finish setting up.'
    }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'
    import { Spinner } from '@/components/ui/spinner'

    let {
        status = ''
    }: {
        status?: string
    } = $props()
</script>

<AppHead title="Email verification" />

{#if status === 'verification-link-sent'}
    <div class="mb-6 text-sm font-medium text-success">
        We sent a new verification link to your email address.
    </div>
{/if}

<Form method="post" action={route('verification.send')} class="space-y-6">
    {#snippet children({ processing })}
        <Button type="submit" disabled={processing} variant="secondary">
            {#if processing}<Spinner />{/if}
            Resend verification email
        </Button>

        <TextLink href={route('logout')} as="button" class="block text-sm">Sign out</TextLink>
    {/snippet}
</Form>
