<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import Wordmark from '@/components/panel/Wordmark.svelte'

    let { command }: { command: string } = $props()
</script>

<AppHead title="Setup required" />

<div class="panel grain relative flex min-h-dvh flex-col bg-background font-sans text-foreground">
    <header class="relative z-10 mx-auto flex w-full max-w-300 items-center px-10 py-5.5 max-sm:px-5">
        <Wordmark />
    </header>

    <main class="relative z-10 mx-auto w-full max-w-300 flex-1 px-10 pt-16 pb-12 max-sm:px-5 max-sm:pt-8">
        <div class="max-w-130">
            <h1 class="type-h1 m-0 mb-1.5">Installed, not yet <MarkerUnderline>configured</MarkerUnderline></h1>
            <p class="type-body-sm mb-7 text-muted-foreground">
                No account exists here yet. Open the one-time link the installer printed at the end of the install to
                create the owner account. This page can't create one without it.
            </p>
            <div class="rounded-xl border border-border bg-card p-6">
                <h3 class="type-h3 m-0 mb-1">Lost the link?</h3>
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Run this on the server. It prints a new link to open. The old one stops working.
                </p>
                <pre class="terminal m-0 text-[12.5px] wrap-anywhere whitespace-pre-wrap">{command}</pre>
            </div>
        </div>
    </main>

    <footer class="relative z-10 border-t border-border">
        <div class="mx-auto flex w-full max-w-300 flex-wrap items-center gap-6 px-10 py-8 max-sm:px-5">
            <span class="text-[12.5px] text-muted-foreground">© 2026 Monoven Ltd · coresmith.io · ELv2 licensed</span>
            <MonovenMark class="ml-auto" />
        </div>
    </footer>
</div>
