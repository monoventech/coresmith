<script lang="ts" module>
    export type DeployScript = {
        script: string
        custom: boolean
        from_deploy: boolean
        migrations: boolean
        migration_commands: string[]
        update_href: string | null
        reset_href: string | null
        migrations_href: string | null
    }
</script>

<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import SectionCard, { bandX } from '@/components/SectionCard.svelte'
    import ToggleRow from '@/components/ToggleRow.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import { cn } from '@/lib/utils'
    import { page, router } from '@inertiajs/svelte'
    import { untrack } from 'svelte'

    const PREVIEW = 12

    let { script, name, class: className }: { script: DeployScript; name: string; class?: string } = $props()

    const uid = $props.id()

    let open = $state(false)
    let draft = $state('')
    let error = $state<string | undefined>()
    let saving = $state(false)
    let resetting = $state(false)
    let migrating = $state(false)
    let gutter = $state<HTMLDivElement | null>(null)

    const lines = $derived(script.script === '' ? [] : script.script.replace(/\n$/, '').split('\n'))
    const shown = $derived(lines.slice(0, PREVIEW))
    const migrate = $derived(script.migration_commands.join(' and '))
    const numbers = $derived(Array.from({ length: Math.max(1, draft.split('\n').length) }, (_, index) => index + 1))

    function edit() {
        draft = script.script
        error = undefined
        open = true
    }

    $effect(() => {
        const url = new URL(page.url, window.location.origin)

        if (script.update_href && url.searchParams.get('script') === 'edit') {
            untrack(edit)
            url.searchParams.delete('script')
            window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`)
        }
    })

    function save(event: SubmitEvent) {
        event.preventDefault()

        if (script.update_href === null) {
            return
        }

        saving = true
        router.put(
            script.update_href,
            { script: draft },
            {
                preserveScroll: true,
                onSuccess: () => (open = false),
                onError: (errors: Record<string, string>) => (error = errors.script),
                onFinish: () => (saving = false)
            }
        )
    }

    function setMigrations(on: boolean) {
        if (script.migrations_href === null) {
            return
        }

        migrating = true
        router.put(
            script.migrations_href,
            { run_migrations: on },
            { preserveScroll: true, onFinish: () => (migrating = false) }
        )
    }

    function reset() {
        if (script.reset_href === null) {
            return
        }

        resetting = true
        router.delete(script.reset_href, { preserveScroll: true, onFinish: () => (resetting = false) })
    }
</script>

{#snippet yourOwn()}
    <Badge variant="outline">Your own</Badge>
{/snippet}

{#snippet scriptActions()}
    {#if script.custom && script.reset_href}
        <Button variant="ghost" size="sm" disabled={resetting} onclick={reset}>Use the default</Button>
    {/if}
    <Button variant="outline" size="sm" onclick={edit}>Edit script</Button>
{/snippet}

<SectionCard
    title="Deploy script"
    description="Runs in the new release before it goes live. Coresmith checks out the commit and writes the environment file first. After the script, it checks the release has what the app needs to start, then switches traffic to it. The first command that fails stops the deploy, and the current release keeps serving."
    badge={script.custom ? yourOwn : undefined}
    actions={script.update_href ? scriptActions : undefined}
    class={cn('max-sm:hidden', className)}
    testid="deploy-script"
>
    <div class="border-t border-border px-5.5 py-4">
        {#if lines.length > 0}
            <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
            <div
                class="focus-ring overflow-x-auto rounded-lg border border-border bg-muted/40 py-2.5"
                tabindex="0"
                role="region"
                aria-label="Deploy script for {name}"
                data-testid="deploy-script-preview"
            >
                <ol class="m-0 min-w-max list-none p-0 font-mono text-[12.5px] leading-[1.7]">
                    {#each shown as line, index (index)}
                        <li class="flex">
                            <span
                                class="w-9 shrink-0 pr-3 text-right text-muted-foreground/70 select-none"
                                aria-hidden="true">{index + 1}</span
                            >
                            <code class="pr-4 whitespace-pre">{line}</code>
                        </li>
                    {/each}
                </ol>
            </div>
            {#if lines.length > PREVIEW}
                <p class="m-0 mt-1.5 text-[12px] text-muted-foreground">
                    and {lines.length - PREVIEW} more {lines.length - PREVIEW === 1 ? 'line' : 'lines'}
                </p>
            {/if}
        {:else}
            <p class="m-0 text-[12.5px] text-muted-foreground">
                Coresmith runs no install or build steps for this app. It serves the release as it is.
            </p>
        {/if}
        {#if !script.custom && !script.from_deploy}
            <p class="m-0 mt-2 text-[12px] text-muted-foreground">
                Coresmith reads the repository on the first deploy. Until then, these are the steps it expects to run.
            </p>
        {/if}
        {#if script.custom && script.migrations}
            <p class="m-0 mt-2 text-[12px] text-muted-foreground">
                Your script decides whether migrations run, so add them to it if the app needs them.
            </p>
        {/if}
    </div>
    {#if !script.custom && script.migration_commands.length > 0}
        <ToggleRow
            label="Run migrations"
            description={script.migrations
                ? `Each deploy runs ${migrate} before the new release goes live.`
                : `Deploys skip ${migrate}. Run ${script.migration_commands.length === 1 ? 'it' : 'them'} yourself when the schema changes.`}
            checked={script.migrations}
            busy={migrating}
            busyDescription={script.migrations ? 'Turning migrations off…' : 'Turning migrations on…'}
            disabled={script.migrations_href === null}
            onchange={setMigrations}
            class={bandX}
            testid="toggle-run-migrations"
        />
    {/if}
</SectionCard>

<Dialog.Root bind:open>
    <Dialog.Content class="sm:max-w-2xl">
        <form onsubmit={save} novalidate data-testid="deploy-script-dialog">
            <Dialog.Header>
                <Dialog.Title class="wrap-anywhere">Deploy script for {name}</Dialog.Title>
                <Dialog.Description
                    >Runs in the new release with the app's runtimes on its path. The first command that fails stops the
                    deploy.</Dialog.Description
                >
            </Dialog.Header>
            <Label for="{uid}-script" class="mt-4 mb-1.5">Script</Label>
            <div
                class="flex max-h-[50vh] min-h-48 overflow-hidden rounded-md border border-input bg-background has-aria-invalid:border-destructive has-[textarea:focus-visible]:border-ring has-[textarea:focus-visible]:shadow-[0_0_0_2px_var(--background),0_0_0_4px_var(--ring)]"
            >
                <div
                    bind:this={gutter}
                    class="w-10 shrink-0 overflow-hidden border-r border-border bg-muted/40 py-2.5 pr-2 text-right font-mono text-[12.5px] leading-[1.7] text-muted-foreground/70 select-none"
                    aria-hidden="true"
                >
                    {#each numbers as number (number)}
                        <div>{number}</div>
                    {/each}
                </div>
                <textarea
                    id="{uid}-script"
                    bind:value={draft}
                    class="min-h-48 w-full resize-none overflow-auto bg-transparent px-3 py-2.5 font-mono text-[12.5px] leading-[1.7] text-foreground outline-none"
                    wrap="off"
                    spellcheck="false"
                    autocapitalize="off"
                    autocomplete="off"
                    aria-invalid={error ? true : undefined}
                    onscroll={(event) => gutter && (gutter.scrollTop = event.currentTarget.scrollTop)}></textarea>
            </div>
            <FieldError message={error} />
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                <Button type="submit" disabled={saving}>Save script</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
