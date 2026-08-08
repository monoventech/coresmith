<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import AppLogoIcon from '@/components/AppLogoIcon.svelte'
    import InputError from '@/components/InputError.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Form } from '@inertiajs/svelte'

    let {
        setupToken,
        timezones = [],
        minPassword
    }: {
        setupToken: string
        timezones: string[]
        minPassword: number
    } = $props()

    const selectClass =
        'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'
</script>

<AppHead title="Set up Coresmith" />

<div class="flex min-h-svh flex-col items-center justify-center gap-10 bg-background px-6 py-12">
    <div class="w-full max-w-lg">
        <div class="mb-8 flex items-center gap-2.5">
            <AppLogoIcon class="size-7 text-primary" />
            <span class="font-display text-lg leading-none font-semibold tracking-[-0.01em]">Coresmith</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-[-0.01em]">Create the <MarkerUnderline>owner</MarkerUnderline> account</h1>
        <p class="mt-2 text-sm leading-relaxed text-muted-foreground" data-testid="install-intro">
            This is the only account that can be created from this page. Once it exists, the setup link stops working
            for good.
        </p>

        <Form method="post" action="/install" class="mt-8 space-y-5">
            {#snippet children({ errors, processing })}
                <input type="hidden" name="setup_token" value={setupToken} />

                <div class="grid gap-2">
                    <Label for="name">Your name</Label>
                    <Input id="name" name="name" required autocomplete="name" />
                    <InputError message={errors.name} />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input id="email" name="email" type="email" required autocomplete="username" />
                    <InputError message={errors.email} />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input id="password" name="password" type="password" required autocomplete="new-password" />
                    <p class="text-xs text-muted-foreground">
                        At least {minPassword} characters, and checked against known breached passwords.
                    </p>
                    <InputError message={errors.password} />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                    />
                </div>

                <hr class="border-border" />

                <div class="grid gap-2">
                    <Label for="instance_name">Instance name</Label>
                    <Input id="instance_name" name="instance_name" required value="Coresmith" />
                    <p class="text-xs text-muted-foreground">
                        Shown in the header, and used as your first team's name.
                    </p>
                    <InputError message={errors.instance_name} />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Timezone</Label>
                    <select
                        id="timezone"
                        name="timezone"
                        class={selectClass}
                    >
                        {#each timezones as zone (zone)}
                            <option value={zone} selected={zone === 'UTC'}>{zone}</option>
                        {/each}
                    </select>
                    <InputError message={errors.timezone} />
                </div>

                <div class="grid gap-2">
                    <Label for="notification_email"
                        >Notification email <span class="text-muted-foreground">(optional)</span></Label
                    >
                    <Input id="notification_email" name="notification_email" type="email" autocomplete="email" />
                    <InputError message={errors.notification_email} />
                </div>

                <Button type="submit" class="w-full" disabled={processing}>
                    {processing ? 'Creating…' : 'Create account and finish setup'}
                </Button>
            {/snippet}
        </Form>
    </div>

    <MonovenMark />
</div>
