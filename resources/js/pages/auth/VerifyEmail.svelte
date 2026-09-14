<script module lang="ts">
    export const layout = {
        title: 'Check your inbox',
        mark: 'inbox',
        description: 'We emailed you a verification link. Open it to finish setting up your account.'
    }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'

    let { status = '' }: { status?: string } = $props()
</script>

<AppHead title="Email verification" />

{#if status === 'verification-link-sent'}
    <p class="tint-success mb-4 rounded-md border px-3.5 py-2.5 text-[13px]">A new verification link is on its way.</p>
{/if}

<Form method="post" action={route('verification.send')}>
    {#snippet children({ processing })}
        <div class="flex flex-wrap items-center gap-4">
            <Button type="submit" disabled={processing} variant="outline">Send the link again</Button>
            <TextLink href={route('logout')} method="post" as="button" class="text-[13px]">Sign out</TextLink>
        </div>
    {/snippet}
</Form>
