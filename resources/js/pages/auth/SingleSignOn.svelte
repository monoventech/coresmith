<script module lang="ts">
    export const layout = {
        title: 'Sign in with single sign-on',
        mark: 'single sign-on',
        description: "Enter your work email and we'll take you to your company's sign-in page."
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
</script>

<AppHead title="Single sign-on" />

<Form method="post" action={route('sso.lookup')}>
    {#snippet children({ errors, processing })}
        <div class="flex flex-col gap-4">
            <div>
                <Label for="email" class="mb-1.5">Work email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    placeholder="you@company.com"
                    aria-invalid={errors.email ? true : undefined}
                />
                <InputError message={errors.email} />
            </div>

            <Button type="submit" class="w-full" disabled={processing} data-test="sso-continue-button">Continue</Button>

            <p class="m-0 text-[13px] text-muted-foreground">
                No single sign-on at work? <TextLink href={route('login')}>Sign in with a password</TextLink>
            </p>
        </div>
    {/snippet}
</Form>
