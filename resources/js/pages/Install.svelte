<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import InputError from '@/components/InputError.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import Wordmark from '@/components/panel/Wordmark.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'

    let {
        setupToken,
        timezones = [],
        minPassword
    }: {
        setupToken: string
        timezones: string[]
        minPassword: number
    } = $props()
</script>

<AppHead title="Set up Coresmith" />

<div class="panel grain relative flex min-h-dvh flex-col bg-background font-sans text-foreground">
    <header class="relative z-10 mx-auto flex w-full max-w-300 items-center px-10 py-5.5 max-sm:px-5">
        <Wordmark />
    </header>

    <main class="relative z-10 mx-auto w-full max-w-300 flex-1 px-10 pt-12 pb-12 max-sm:px-5 max-sm:pt-6">
        <h1 class="type-h1 m-0 mb-1.5">Create the <MarkerUnderline>owner</MarkerUnderline> account</h1>
        <p class="type-body-sm mb-7 max-w-[60ch] text-muted-foreground" data-testid="install-intro">
            This is the only account that can be created from this page. Once it exists, the setup link stops working
            for good.
        </p>

        <Form method="post" action="/install">
            {#snippet children({ errors, processing })}
                <input type="hidden" name="setup_token" value={setupToken} />
                <div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
                    <div class="rounded-xl border border-border bg-card p-6">
                        <h3 class="type-h3 m-0 mb-1">You</h3>
                        <p class="mb-4 text-[12.5px] text-muted-foreground">
                            The owner can provision and delete servers, and hand the panel to someone else later.
                        </p>
                        <div class="flex flex-col gap-4">
                            <div>
                                <Label for="name" class="mb-1.5">Your name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autocomplete="name"
                                    aria-invalid={errors.name ? true : undefined}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div>
                                <Label for="email" class="mb-1.5">Email</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    required
                                    autocomplete="username"
                                    aria-invalid={errors.email ? true : undefined}
                                />
                                <InputError message={errors.email} />
                            </div>
                            <div>
                                <Label for="password" class="mb-1.5">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autocomplete="new-password"
                                    aria-invalid={errors.password ? true : undefined}
                                />
                                <p class="mt-1.5 text-[12px] text-muted-foreground">
                                    At least {minPassword} characters, checked against known breached passwords.
                                </p>
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
                    </div>

                    <div class="rounded-xl border border-border bg-card p-6">
                        <h3 class="type-h3 m-0 mb-1">This panel</h3>
                        <p class="mb-4 text-[12.5px] text-muted-foreground">
                            You can change all of this later in Settings.
                        </p>
                        <div class="flex flex-col gap-4">
                            <div>
                                <Label for="instance_name" class="mb-1.5">Instance name</Label>
                                <Input
                                    id="instance_name"
                                    name="instance_name"
                                    required
                                    value="Coresmith"
                                    aria-invalid={errors.instance_name ? true : undefined}
                                />
                                <p class="mt-1.5 text-[12px] text-muted-foreground">Used for your first team's name.</p>
                                <InputError message={errors.instance_name} />
                            </div>
                            <div>
                                <Label for="timezone" class="mb-1.5">Timezone</Label>
                                <NativeSelect id="timezone" name="timezone" value="UTC" options={timezones} />
                                <InputError message={errors.timezone} />
                            </div>
                            <div>
                                <Label for="notification_email" class="mb-1.5">Alert email, optional</Label>
                                <Input
                                    id="notification_email"
                                    name="notification_email"
                                    type="email"
                                    autocomplete="email"
                                    aria-invalid={errors.notification_email ? true : undefined}
                                />
                                <InputError message={errors.notification_email} />
                            </div>
                            <Button type="submit" class="w-full" disabled={processing}>
                                {processing ? 'Creating…' : 'Create account and finish setup'}
                            </Button>
                        </div>
                    </div>
                </div>
            {/snippet}
        </Form>
    </main>

    <footer class="relative z-10 border-t border-border">
        <div class="mx-auto flex w-full max-w-300 flex-wrap items-center gap-6 px-10 py-8 max-sm:px-5">
            <span class="text-[12.5px] text-muted-foreground">© 2026 Monoven Ltd · coresmith.io · ELv2 licensed</span>
            <MonovenMark class="ml-auto" />
        </div>
    </footer>
</div>
