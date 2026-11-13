<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import { Check } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'

    type App = {
        id: string
        name: string
        href: string
        user: string
        ports: string
        folder: string
        processes: string[]
    }

    let {
        server,
        apps = [],
        services = [],
        isolation = [],
        files = []
    }: {
        server: { id: string; name: string; href: string; hardening: string }
        apps: App[]
        services: { name: string; detail: string }[]
        isolation: string[]
        files: { path: string; what: string }[]
    } = $props()
</script>

<AppHead title="What Coresmith runs on {server.name}" />

<BackLink href={server.href} label={server.name} />

<h1 class="type-h1 m-0 mb-1.5">What Coresmith runs here</h1>
<p class="type-body-sm mb-7 max-w-[68ch] text-muted-foreground">
    This is what you'll find when you sign in to <span class="font-mono text-[13px]">{server.name}</span> over SSH.
    Coresmith manages all of this, so change it through Coresmith rather than by hand. Its protections are on the
    <Link href={server.hardening} class="text-foreground underline underline-offset-2">Hardening tab</Link>.
</p>

<div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="machinery-apps">
    <h3 class="type-h3 m-0 border-b border-border px-5.5 py-3.5">Apps</h3>
    {#each apps as app (app.id)}
        <div class="border-b border-border px-5.5 py-3.5 last:border-b-0" data-testid="machinery-app">
            <Link
                href={app.href}
                class="focus-ring mb-2 inline-block rounded-sm font-mono text-[14px] font-medium text-foreground no-underline hover:underline"
                >{app.name}</Link
            >
            <dl
                class="m-0 grid grid-cols-[7rem_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[12.5px] max-sm:grid-cols-1 max-sm:gap-y-0.5"
            >
                <dt class="text-muted-foreground max-sm:mt-1.5">Linux user</dt>
                <dd class="m-0 font-mono wrap-anywhere">{app.user}</dd>
                <dt class="text-muted-foreground max-sm:mt-1.5">Ports</dt>
                <dd class="m-0 font-mono">{app.ports}</dd>
                <dt class="text-muted-foreground max-sm:mt-1.5">Folder</dt>
                <dd class="m-0 font-mono wrap-anywhere">{app.folder}</dd>
                <dt class="text-muted-foreground max-sm:mt-1.5">Processes</dt>
                <dd class="m-0 flex flex-col font-mono wrap-anywhere">
                    {#each app.processes as process (process)}<span>{process}</span>{:else}<span
                            class="text-muted-foreground">None yet</span
                        >{/each}
                </dd>
            </dl>
        </div>
    {:else}
        <p class="m-0 px-5.5 py-3.5 text-[12.5px] text-muted-foreground">No apps on this server yet.</p>
    {/each}
</div>

<div class="mt-4 grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="machinery-services">
        <h3 class="type-h3 m-0 mb-3">Services</h3>
        <dl class="m-0 flex flex-col gap-3">
            {#each services as service (service.name)}
                <div>
                    <dt class="font-mono text-[13px] font-medium">{service.name}</dt>
                    <dd class="m-0 text-[12.5px] text-muted-foreground">{service.detail}</dd>
                </div>
            {/each}
        </dl>
    </div>

    <div class="rounded-xl border border-border bg-card px-5.5 py-5" data-testid="machinery-isolation">
        <h3 class="type-h3 m-0 mb-1">How apps are kept apart</h3>
        <p class="mb-3.5 text-[12.5px] text-muted-foreground">Every app on this server gets each of these.</p>
        <ul class="m-0 flex list-none flex-col gap-2.25 p-0">
            {#each isolation as layer (layer)}
                <li class="flex items-start gap-2.5 text-[13.5px]">
                    <Check class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />{layer}
                </li>
            {/each}
        </ul>
    </div>
</div>

<div class="mt-4 rounded-xl border border-border bg-card px-5.5 py-5" data-testid="machinery-files">
    <h3 class="type-h3 m-0 mb-3">Files Coresmith manages</h3>
    <dl class="m-0 flex flex-col">
        {#each files as file (file.path)}
            <div
                class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] gap-x-5 gap-y-1 border-t border-border py-2.5 max-sm:grid-cols-1"
            >
                <dt class="font-mono text-[12.5px] break-all">{file.path}</dt>
                <dd class="m-0 text-[12.5px] text-muted-foreground">{file.what}</dd>
            </div>
        {/each}
    </dl>
</div>
