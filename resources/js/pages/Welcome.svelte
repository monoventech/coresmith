<script lang="ts">
    import { Link, page } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import AppLogoIcon from '@/components/AppLogoIcon.svelte'
    import BlueprintRack from '@/components/illustrations/BlueprintRack.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import { Button } from '@/components/ui/button'

    let {
        canRegister = true
    }: {
        canRegister: boolean
    } = $props()

    const auth = $derived(page.props.auth)
    const appName = $derived(page.props.appName)
</script>

<AppHead title="Provision servers and ship apps from your own machines" />

<div class="flex min-h-svh flex-col bg-background">
    <header class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6">
        <Link href={route('home')} class="flex items-center gap-2.5">
            <AppLogoIcon class="size-7 text-primary" />
            <span class="font-display text-lg leading-none font-semibold tracking-[-0.01em]">{appName}</span>
        </Link>

        <nav class="flex items-center gap-2">
            {#if auth.user}
                <Button href={route('dashboard')} variant="outline" size="sm">Dashboard</Button>
            {:else}
                <Button href={route('login')} variant="ghost" size="sm">Sign in</Button>
                {#if canRegister}
                    <Button href={route('register')} size="sm">Create an account</Button>
                {/if}
            {/if}
        </nav>
    </header>

    <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 py-20">
        <div class="grid items-center gap-16 lg:grid-cols-[1.15fr_1fr]">
            <div class="max-w-xl">
                <h1 class="font-display text-5xl leading-[1.02] font-semibold tracking-[-0.02em] sm:text-6xl">
                    Provision servers and ship apps from your own
                    <MarkerUnderline>machines</MarkerUnderline>.
                </h1>

                <p class="mt-6 max-w-lg text-lg leading-relaxed text-muted-foreground">
                    {appName} sets up your servers, keeps them patched, and deploys your applications straight to them. No Docker. No
                    lock-in. No monthly rent on someone else's hardware.
                </p>

                <div class="mt-10">
                    <Button href={auth.user ? route('dashboard') : route('register')} size="lg">
                        {auth.user ? 'Go to your dashboard' : 'Start forging'}
                    </Button>
                </div>
            </div>

            <div class="hidden justify-center lg:flex">
                <BlueprintRack class="w-64 text-border" />
            </div>
        </div>
    </main>

    <footer class="mx-auto w-full max-w-5xl border-t border-border px-6 py-8">
        <MonovenMark />
    </footer>
</div>
