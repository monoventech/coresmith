<script lang="ts">
    import { Form, Link, page } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'

    let {
        mustVerifyEmail,
        status = ''
    }: {
        mustVerifyEmail: boolean
        status?: string
    } = $props()

    const user = $derived(page.props.auth.user)
</script>

<AppHead title="Your account" />

<div class="max-w-[660px] rounded-xl border border-border bg-card p-6" data-testid="profile-card">
    <h3 class="type-h3 m-0 mb-1">Profile</h3>
    <p class="mb-4 text-[12.5px] text-muted-foreground">Your name shows on deploys you trigger and in the team list.</p>

    <Form method="patch" action={route('profile.update')} options={{ preserveScroll: true }}>
        {#snippet children({ errors, processing })}
            <div class="flex flex-col gap-4">
                <div>
                    <Label for="name" class="mb-1.5">Name</Label>
                    <Input id="name" name="name" value={user.name} required autocomplete="name" aria-invalid={errors.name ? true : undefined} />
                    <FieldError message={errors.name} />
                </div>

                <div>
                    <Label for="email" class="mb-1.5">Email address</Label>
                    <Input id="email" type="email" name="email" value={user.email} required autocomplete="username" aria-invalid={errors.email ? true : undefined} />
                    <FieldError message={errors.email} />
                    {#if mustVerifyEmail && !user.email_verified_at}
                        <p class="mt-2 text-[12px] text-muted-foreground">
                            This address isn't verified yet.
                            <Link href={route('verification.send')} method="post" as="button" class="focus-ring cursor-pointer rounded-sm bg-transparent p-0 text-[12px] font-medium text-primary">
                                Send the link again
                            </Link>
                        </p>
                        {#if status === 'verification-link-sent'}
                            <p class="mt-1 text-[12px] font-medium text-success">A new verification link is on its way.</p>
                        {/if}
                    {/if}
                </div>

                <div>
                    <Button type="submit" disabled={processing} data-test="update-profile-button">Save</Button>
                </div>
            </div>
        {/snippet}
    </Form>
</div>
