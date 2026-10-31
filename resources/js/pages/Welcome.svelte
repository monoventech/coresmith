<script lang="ts">
    import { Link, page } from '@inertiajs/svelte'
    import { Check, GitBranch, Rocket, Server } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import Wordmark from '@/components/panel/Wordmark.svelte'
    import { Button } from '@/components/ui/button'

    let { canRegister = true }: { canRegister: boolean } = $props()

    const SOURCE = 'https://github.com/monoven/coresmith'

    const signedIn = $derived(Boolean(page.props.auth?.user))
    const panelHref = $derived(signedIn ? route('dashboard') : route('login'))
    const startHref = $derived(signedIn ? route('dashboard') : canRegister ? route('register') : route('login'))

    const stack = [
        { name: 'atlas', kind: 'Laravel · PHP 8.5', ports: '8100–8119' },
        { name: 'ledger', kind: 'Rails · Ruby 3.3', ports: '8120–8139' },
        { name: 'relay', kind: 'API · Deno 2.1', ports: '8140–8159' },
        { name: 'storefront', kind: 'SvelteKit · Bun 1.2', ports: '8160–8179' }
    ]
</script>

<AppHead title="Server management without the complexity" />

<div class="dark">
    <div class="panel grain relative min-h-dvh bg-background font-sans text-foreground">
        <header
            class="relative z-10 mx-auto flex max-w-300 flex-wrap items-center gap-7 px-10 py-5.5 max-sm:gap-4 max-sm:px-5"
        >
            <Link
                href={route('home')}
                class="focus-ring rounded-md text-foreground no-underline"
                aria-label="Coresmith home"
            >
                <Wordmark />
            </Link>
            <nav class="ml-auto flex items-center gap-5.5 text-[14px] font-medium" aria-label="Site">
                <a
                    href="#how"
                    class="focus-ring rounded-sm text-muted-foreground no-underline underline-offset-4 hover:underline max-sm:hidden"
                    >How it works</a
                >
                <a
                    href="#security"
                    class="focus-ring rounded-sm text-muted-foreground no-underline underline-offset-4 hover:underline max-sm:hidden"
                    >Security</a
                >
                <a
                    href={SOURCE}
                    class="focus-ring rounded-sm text-muted-foreground no-underline underline-offset-4 hover:underline max-sm:hidden"
                    rel="noopener noreferrer">Source</a
                >
                <Button size="sm" href={panelHref}>Open the panel</Button>
            </nav>
        </header>

        <section class="relative z-10 mx-auto max-w-300 px-10 pt-24 pb-18 max-sm:px-5 max-sm:pt-12">
            <h1 class="type-display-lg m-0 max-w-[15ch] text-pretty max-sm:text-[2.5rem]">
                Server management without the <MarkerUnderline>complexity.</MarkerUnderline>
            </h1>
            <p class="type-body-lg mt-6 max-w-[52ch] text-muted-foreground">
                Deploy any app, any language, any server, natively on the host OS. No Docker daemon, no image layers, no
                container overhead. Zero-downtime releases with instant rollback.
            </p>
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <Button size="lg" href={panelHref}>See it deploy</Button>
                <Button size="lg" variant="outline" href="{SOURCE}#self-hosting" rel="noopener noreferrer"
                    >Self-host it</Button
                >
            </div>
            <p class="mt-4 text-[12.5px] text-muted-foreground">
                Source-available under ELv2, every feature, no enterprise edition.
            </p>
        </section>

        <section class="relative z-10 mx-auto max-w-300 px-10 pb-24 max-sm:px-5">
            <div class="terminal overflow-x-auto px-6 py-5">
                <div class="text-[oklch(0.62_0.008_80)]">
                    # one push to main. the old release serves traffic the whole time.
                </div>
                <div>[14:02:18] release 20260818-1402 · branch main · commit 9f3c2e1</div>
                <div>[14:02:24] deps installed · assets built · migrations run, against the new release</div>
                <div>[14:03:58] health check passed before anything went live</div>
                <div>
                    [14:04:09] symlink: current → releases/20260818-1402 <span class="text-primary">(atomic swap)</span>
                </div>
                <div>[14:04:11] deployed. previous release kept for instant rollback.</div>
            </div>
        </section>

        <section id="how" class="relative z-10 border-t border-border">
            <div class="mx-auto max-w-300 px-10 py-24 max-sm:px-5 max-sm:py-16">
                <h2 class="type-display-md m-0 mb-12">How it works</h2>
                <div class="grid grid-cols-3 gap-4 max-[900px]:grid-cols-1">
                    <div class="rounded-xl border border-border bg-card p-6">
                        <Server class="size-5 text-primary" />
                        <h3 class="type-h3 mt-3.5 mb-2">Provision, hardened by default</h3>
                        <p class="type-body-sm m-0 text-muted-foreground">
                            Eight phases from blank VM to production host: key-only SSH, fail2ban, kernel hardening,
                            AppArmor checks, a default-deny firewall, auto-patching. Not opt-in, the default.
                        </p>
                    </div>
                    <div class="rounded-xl border border-border bg-card p-6">
                        <GitBranch class="size-5 text-primary" />
                        <h3 class="type-h3 mt-3.5 mb-2">Connect a repository</h3>
                        <p class="type-body-sm m-0 text-muted-foreground">
                            A GitHub App with per-repo permissions. Each deploy clones with a read-only token that lasts
                            an hour, or a read-only Ed25519 deploy key for repositories added by URL. Queue workers,
                            schedulers and maintenance pages become toggles.
                        </p>
                    </div>
                    <div class="rounded-xl border border-border bg-card p-6">
                        <Rocket class="size-5 text-primary" />
                        <h3 class="type-h3 mt-3.5 mb-2">Push to deploy</h3>
                        <p class="type-body-sm m-0 text-muted-foreground">
                            Every deploy builds in a new timestamped directory while the old release serves traffic. One
                            atomic symlink swap at the end. Rollback is re-pointing the symlink, instant.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section id="security" class="relative z-10 border-t border-border">
            <div
                class="mx-auto grid max-w-300 grid-cols-2 items-start gap-16 px-10 py-24 max-[900px]:grid-cols-1 max-sm:px-5 max-sm:py-16"
            >
                <div>
                    <h2 class="type-display-md m-0 mb-4">One server, every stack</h2>
                    <p class="type-body m-0 mb-6 max-w-[48ch] text-muted-foreground">
                        Laravel on PHP 8.5, Rails on Ruby 3.3, a SvelteKit frontend on Bun and a Deno API, on the same
                        machine, isolated across nine independent boundaries. A compromise in one app cannot reach
                        another without defeating all nine.
                    </p>
                    <ul class="m-0 flex list-none flex-col gap-2.5 p-0 text-[14px]">
                        <li class="flex items-center gap-2.5">
                            <Check class="size-4 shrink-0 text-success" />Own Linux user, ports, database credentials
                            and env file per app
                        </li>
                        <li class="flex items-center gap-2.5">
                            <Check class="size-4 shrink-0 text-success" />Root-owned env files, apps can't read their
                            own secrets
                        </li>
                        <li class="flex items-center gap-2.5">
                            <Check class="size-4 shrink-0 text-success" />No container runtime, so no daemon eating
                            memory on every server
                        </li>
                    </ul>
                </div>
                <div class="rounded-xl border border-border bg-card p-6">
                    <div class="type-caption mb-3.5 text-muted-foreground uppercase">On forge-01 right now</div>
                    <div class="flex flex-col">
                        {#each stack as app, index (app.name)}
                            <div
                                class="flex items-center gap-3 py-2.75 {index < stack.length - 1
                                    ? 'border-b border-border'
                                    : ''}"
                            >
                                <span class="size-1.5 shrink-0 rounded-full bg-success" aria-hidden="true"></span>
                                <span class="font-mono text-[14px] font-medium">{app.name}</span>
                                <span class="text-[13px] text-muted-foreground max-sm:hidden">{app.kind}</span>
                                <span class="ml-auto font-mono text-[12px] text-muted-foreground">{app.ports}</span>
                            </div>
                        {/each}
                    </div>
                </div>
            </div>
        </section>

        <section class="relative z-10 border-t border-border">
            <div class="mx-auto max-w-300 px-10 py-24 max-sm:px-5 max-sm:py-16">
                <h2 class="type-display-md m-0 mb-3 max-w-[22ch]">Read the source. All of it.</h2>
                <p class="type-body m-0 mb-7 max-w-[52ch] text-muted-foreground">
                    Licensed under ELv2. Every feature in Coresmith Cloud is in the self-hosted version, no community
                    edition, no feature gates. The only restriction: don't resell it as a hosted service.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <Button size="lg" href={startHref}>Get started</Button>
                    <Button size="lg" variant="ghost" href={SOURCE} rel="noopener noreferrer">Browse on GitHub</Button>
                </div>
            </div>
        </section>

        <footer class="relative z-10 border-t border-border">
            <div class="mx-auto flex max-w-300 flex-wrap items-center gap-6 px-10 py-8 max-sm:px-5">
                <span class="text-[12.5px] text-muted-foreground"
                    >© 2026 Monoven Ltd · coresmith.io · ELv2 licensed</span
                >
                <MonovenMark class="ml-auto" />
            </div>
        </footer>
    </div>
</div>
