<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import AppLogoIcon from '@/components/AppLogoIcon.svelte'
</script>

<AppHead title="Setup required" />

<div class="flex min-h-svh items-center justify-center bg-background px-6 py-12">
    <main class="w-full max-w-md text-center">
        <div class="mb-6 flex items-center justify-center gap-2.5">
            <AppLogoIcon class="size-7 text-primary" />
            <span class="font-display text-lg leading-none font-semibold tracking-[-0.01em]">Coresmith</span>
        </div>

        <h1 class="text-xl font-semibold">Installed, but not yet configured</h1>
        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
            No account exists on this instance yet. Open the setup link printed by the installer in your terminal — it
            carries a one-time token, and this page cannot create an account without it.
        </p>

        <p class="mt-4 text-xs text-muted-foreground">
            Lost the link? Run <code class="rounded bg-muted px-1.5 py-0.5 font-mono">php artisan coresmith:setup-token --force</code>
            on the server to issue a new one.
        </p>
    </main>
</div>
