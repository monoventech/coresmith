<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Form, page, setLayoutProps } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'

    type Invitation = { team: string; email: string; role: string; inviter: string | null }

    let {
        state,
        invitation = null,
        action = null,
        trust_note: trustNote = false
    }: {
        state: 'expired' | 'register' | 'sign-in' | 'join' | 'other'
        invitation?: Invitation | null
        action?: string | null
        trust_note?: boolean
    } = $props()

    const signedIn = $derived(page.props.auth.user !== null)

    const content = $derived.by(() => {
        if (state === 'expired' || invitation === null) {
            return {
                title: 'Invitation expired',
                mark: 'expired',
                description: 'This invitation has expired or was already used. Ask someone on the team for a new one.'
            }
        }

        const invited = invitation.inviter
            ? `${invitation.inviter} invited you as ${invitation.role}.`
            : `You are invited as ${invitation.role}.`

        const description = {
            register: `${invited} Create your account to accept.`,
            'sign-in': `${invited} You already have an account here, so sign in to accept.`,
            join: `${invited} You are signed in as ${invitation.email}.`,
            other: `This invitation is for ${invitation.email}. Sign out, then open the link again.`
        }[state]

        return { title: `Join ${invitation.team}`, mark: invitation.team, description }
    })

    $effect(() => {
        setLayoutProps({ title: content.title, mark: content.mark, description: content.description })
    })
</script>

<AppHead title={content.title} />

{#snippet trust()}
    {#if trustNote}
        <p class="m-0 text-[13px] text-muted-foreground" data-testid="trust-note">
            By joining this Coresmith instance, your connected Git provider tokens will be accessible to the instance
            administrator.
        </p>
    {/if}
{/snippet}

{#if state === 'expired' || invitation === null}
    {#if signedIn}
        <Button href={route('dashboard')} variant="outline" class="w-full">Go to your fleet</Button>
    {:else}
        <p class="m-0 text-[13px] text-muted-foreground">
            Already on a team? <TextLink href={route('login')}>Sign in</TextLink>
        </p>
    {/if}
{:else if state === 'register' && action}
    <Form method="post" {action} resetOnError={['password', 'password_confirmation']}>
        {#snippet children({ errors, processing })}
            <div class="flex flex-col gap-4">
                <div>
                    <Label for="email" class="mb-1.5">Email address</Label>
                    <Input id="email" type="email" value={invitation.email} readonly autocomplete="username" />
                </div>

                <div>
                    <Label for="name" class="mb-1.5">Name</Label>
                    <Input
                        id="name"
                        type="text"
                        name="name"
                        required
                        autocomplete="name"
                        aria-invalid={errors.name ? true : undefined}
                    />
                    <InputError message={errors.name} />
                </div>

                <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                    <div>
                        <Label for="password" class="mb-1.5">Password</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            required
                            autocomplete="new-password"
                            aria-invalid={errors.password ? true : undefined}
                        />
                        <InputError message={errors.password} />
                    </div>
                    <div>
                        <Label for="password_confirmation" class="mb-1.5">Confirm password</Label>
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            required
                            autocomplete="new-password"
                        />
                    </div>
                </div>

                {@render trust()}

                <Button type="submit" class="w-full" disabled={processing}>Create account and join</Button>
            </div>
        {/snippet}
    </Form>
{:else if state === 'sign-in'}
    <div class="flex flex-col gap-4">
        {@render trust()}
        <Button href={route('login')} class="w-full">Sign in to join {invitation.team}</Button>
    </div>
{:else if state === 'join' && action}
    <Form method="post" {action}>
        {#snippet children({ processing })}
            <div class="flex flex-col gap-4">
                {@render trust()}
                <Button type="submit" class="w-full" disabled={processing}>Join {invitation.team}</Button>
            </div>
        {/snippet}
    </Form>
{:else}
    <Form method="post" action={route('logout')}>
        {#snippet children({ processing })}
            <Button type="submit" variant="outline" class="w-full" disabled={processing}>Sign out</Button>
        {/snippet}
    </Form>
{/if}
