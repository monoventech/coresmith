<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import { TriangleAlert } from '@lucide/svelte'
    import { untrack } from 'svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import { Button } from '@/components/ui/button'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import * as RadioGroup from '@/components/ui/radio-group'
    import type { ServerHeader } from '@/lib/servers'
    import { cn } from '@/lib/utils'

    type Mode = { allowed: boolean; reason: string | null }
    type App = { key: string; name: string; user: string }

    let {
        server,
        modes,
        applications = [],
        chosen = null,
        password = true,
        unavailable = null,
        open_href: openHref
    }: {
        server: ServerHeader
        modes: { app: Mode; root: Mode }
        applications?: App[]
        chosen?: string | null
        password?: boolean
        unavailable?: string | null
        open_href: string
    } = $props()

    const LOOKS_AFTER = [
        "The web server's settings, and each app's addresses and certificates",
        "Each app's folders, processes and scheduled jobs",
        'The firewall rules',
        "Coresmith's own sign-in key and account, which it uses to reach this server"
    ]

    const appUsable = $derived(modes.app.allowed && applications.length > 0)

    let mode = $state<'root' | 'app'>(
        untrack(() => (modes.root.allowed && !(chosen && modes.app.allowed) ? 'root' : 'app'))
    )
    let application = $state(
        untrack(() => applications.find((app) => app.key === chosen)?.key ?? applications[0]?.key ?? '')
    )

    const size = $derived.by(() => {
        if (typeof window === 'undefined') {
            return { cols: 120, rows: 32 }
        }

        return {
            cols: Math.max(40, Math.min(240, Math.floor((window.innerWidth - 120) / 8.4))),
            rows: Math.max(12, Math.min(80, Math.floor((window.innerHeight - 240) / 18)))
        }
    })
    const ready = $derived(unavailable === null && (mode === 'root' ? modes.root.allowed : appUsable))
</script>

<AppHead title="Terminal · {server.name}" />

<BackLink href={server.tabs.apps} label={server.name} />

<div class="mx-auto max-w-[46rem]">
    <p class="m-0 font-mono text-[12.5px] text-muted-foreground">{server.name}</p>
    <h1 class="type-h1 m-0 mt-1.5 max-sm:text-[24px]">Open a terminal</h1>
    <p class="m-0 mt-1.5 text-[14px] text-muted-foreground">
        A shell on {server.name} in your browser, for anything Coresmith doesn't do for you: your own services, tools and
        experiments.
    </p>

    {#if unavailable}
        <div
            class="tint-warning mt-5 flex items-start gap-3 rounded-xl border px-4.5 py-3"
            role="status"
            data-testid="terminal-unavailable"
        >
            <TriangleAlert class="mt-0.5 size-4.5 shrink-0 text-warning" aria-hidden="true" />
            <span class="text-[13.5px]">{unavailable}</span>
        </div>
    {/if}

    <Form method="post" action={openHref} class="mt-6 flex flex-col gap-6" data-testid="terminal-start">
        {#snippet children({ errors, processing })}
            <input type="hidden" name="mode" value={mode} />
            <input type="hidden" name="cols" value={size.cols} />
            <input type="hidden" name="rows" value={size.rows} />

            <fieldset class="m-0 border-0 p-0">
                <legend class="mb-2.5 p-0 text-[14px] font-medium">Open it as</legend>
                <RadioGroup.Root
                    value={mode}
                    onValueChange={(value) => (mode = value === 'app' ? 'app' : 'root')}
                    aria-label="Open it as"
                    class="gap-2.5"
                >
                    <label
                        class={cn(
                            'flex items-start gap-3 rounded-xl border px-4 py-3.5',
                            modes.root.allowed ? 'cursor-pointer hover:bg-muted' : 'cursor-not-allowed opacity-80',
                            mode === 'root' && modes.root.allowed ? 'option-selected' : 'border-border'
                        )}
                        data-testid="terminal-mode-root"
                    >
                        <RadioGroup.Item
                            value="root"
                            disabled={!modes.root.allowed}
                            aria-labelledby="terminal-root-label"
                            aria-describedby="terminal-root-summary"
                            class="mt-0.5"
                        />
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span id="terminal-root-label" class="text-[14px] font-medium">Root</span>
                            <span id="terminal-root-summary" class="text-[13px] text-muted-foreground">
                                {#if modes.root.allowed}
                                    Full control of the server. Install packages, run containers or a VM, change system
                                    settings. Only owners can open one, while Root terminals is on under Settings ›
                                    Team.
                                {:else}
                                    {modes.root.reason}
                                {/if}
                            </span>
                        </span>
                    </label>

                    <label
                        class={cn(
                            'flex items-start gap-3 rounded-xl border px-4 py-3.5',
                            appUsable ? 'cursor-pointer hover:bg-muted' : 'cursor-not-allowed opacity-80',
                            mode === 'app' && appUsable ? 'option-selected' : 'border-border'
                        )}
                        data-testid="terminal-mode-app"
                    >
                        <RadioGroup.Item
                            value="app"
                            disabled={!appUsable}
                            aria-labelledby="terminal-app-label"
                            aria-describedby="terminal-app-summary"
                            class="mt-0.5"
                        />
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span id="terminal-app-label" class="text-[14px] font-medium">An app's user</span>
                            <span id="terminal-app-summary" class="text-[13px] text-muted-foreground">
                                {#if !modes.app.allowed}
                                    {modes.app.reason}
                                {:else if applications.length === 0}
                                    No app on this server has a live release yet. Deploy one first.
                                {:else}
                                    Starts in the app's live release, as the user it runs as. Good for a console, a
                                    migration or clearing a cache.
                                {/if}
                            </span>
                        </span>
                    </label>
                </RadioGroup.Root>
                <FieldError message={errors.mode} />

                {#if mode === 'app' && appUsable}
                    <div class="mt-3 pl-11 max-sm:pl-0">
                        <Label for="terminal-app" class="mb-1.5">App</Label>
                        <NativeSelect
                            id="terminal-app"
                            name="application"
                            bind:value={application}
                            class="w-full max-w-80"
                            aria-invalid={errors.application ? true : undefined}
                        >
                            {#each applications as app (app.key)}
                                <option value={app.key}>{app.name}, as {app.user}</option>
                            {/each}
                        </NativeSelect>
                        <FieldError message={errors.application} />
                    </div>
                {/if}
            </fieldset>

            <section
                class="rounded-xl border border-border bg-card px-5 py-4.5 max-sm:px-4"
                aria-labelledby="terminal-looks-after"
            >
                <h2 id="terminal-looks-after" class="m-0 text-[14px] font-medium">Coresmith looks after these</h2>
                <ul class="m-0 mt-2.5 flex list-disc flex-col gap-1.5 pl-5 text-[13px] text-muted-foreground">
                    {#each LOOKS_AFTER as item (item)}
                        <li>{item}</li>
                    {/each}
                </ul>
                <p class="m-0 mt-3 text-[13px]">
                    Change anything else freely. When you finish, a check names anything here that changed and what
                    Coresmith does about it. Removing Coresmith's key cuts it off from the server.
                </p>
            </section>

            {#if password}
                <div>
                    <Label for="terminal-password" class="mb-1.5">Your password</Label>
                    <PasswordInput
                        id="terminal-password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="max-w-80"
                        aria-invalid={errors.password ? true : undefined}
                        aria-describedby="terminal-password-hint"
                    />
                    <p id="terminal-password-hint" class="m-0 mt-1.5 text-[12.5px] text-muted-foreground">
                        Asked once every 15 minutes.
                    </p>
                    <FieldError message={errors.password} />
                </div>
            {/if}

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2.5">
                <Button type="submit" disabled={!ready || processing} class="max-sm:w-full" data-testid="terminal-open">
                    {processing ? 'Opening…' : 'Open terminal'}
                </Button>
                <p class="m-0 text-[12.5px] text-muted-foreground">
                    Ends on its own after 10 minutes without typing, after an hour, or when you leave the page.
                </p>
            </div>
        {/snippet}
    </Form>
</div>
