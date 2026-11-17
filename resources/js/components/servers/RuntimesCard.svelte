<script module lang="ts">
    export type RuntimeApp = { id: string; name: string; href: string; pinned: string | null; deployable: boolean }

    export type RuntimeRow = {
        runtime: string
        name: string
        version: string
        label: string
        status: { label: string; tone: 'success' | 'warning' | 'destructive' | 'default' }
        newest: string | null
        eol: boolean
        applications: RuntimeApp[]
    }

    export type Runtimes = {
        rows: RuntimeRow[]
        update: { label: string; state: 'installing' | 'failed'; reason: string | null } | null
        can_update: boolean
        href: string
    }
</script>

<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, router } from '@inertiajs/svelte'
    import FieldError from '@/components/FieldError.svelte'
    import StateDot from '@/components/StateDot.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'

    let { server, runtimes }: { server: string; runtimes: Runtimes } = $props()

    let picked = $state<RuntimeRow | null>(null)
    let chosen = $state<Record<string, boolean>>({})
    let working = $state(false)
    let error = $state<string | null>(null)

    const installing = $derived(runtimes.update?.state === 'installing')

    function movable(row: RuntimeRow): RuntimeApp[] {
        return row.applications.filter((application) => application.pinned === null && application.deployable)
    }

    function open(row: RuntimeRow) {
        picked = row
        chosen = Object.fromEntries(
            row.applications.map((application) => [application.id, movable(row).includes(application)])
        )
        error = null
    }

    function submit(event: SubmitEvent) {
        event.preventDefault()

        if (picked === null) {
            return
        }

        working = true
        router.post(
            runtimes.href,
            {
                runtime: picked.runtime,
                version: picked.version,
                applications: Object.keys(chosen).filter((id) => chosen[id])
            },
            {
                preserveScroll: true,
                onSuccess: () => (picked = null),
                onError: (errors: Record<string, string>) =>
                    (error = errors.applications ?? Object.values(errors)[0] ?? null),
                onFinish: () => (working = false)
            }
        )
    }
</script>

<section
    class="mt-6 overflow-hidden rounded-xl border border-border bg-card"
    aria-labelledby="runtimes-heading"
    data-testid="runtimes-card"
>
    <div class="border-b border-border px-5.5 py-4">
        <h2 id="runtimes-heading" class="type-h3 m-0 mb-1">Runtimes</h2>
        <p class="m-0 text-[12.5px] text-muted-foreground">
            The versions the apps here run, and the newest patch for each. Patches fix bugs and security holes.
        </p>
    </div>

    {#if runtimes.update}
        <div
            role="status"
            class="flex items-start gap-2.5 border-b border-border px-5.5 py-3 text-[12.5px]"
            data-testid="runtime-update"
        >
            <span class="mt-1.5"
                ><StateDot
                    tone={installing ? 'accent' : 'destructive'}
                    state={installing ? 'running' : ''}
                    size="sm"
                /></span
            >
            <span>
                {#if installing}
                    Installing {runtimes.update.label}. The apps deploy again once it's in.
                {:else}
                    Coresmith couldn't install {runtimes.update.label}{runtimes.update.reason
                        ? `: ${runtimes.update.reason}`
                        : '.'} No app was deployed, so try again from its row.
                {/if}
            </span>
        </div>
    {/if}

    {#each runtimes.rows as row (`${row.runtime}@${row.version}`)}
        <div
            class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1.5 border-b border-border px-5.5 py-3.5 last:border-b-0 sm:grid-cols-[minmax(14rem,18rem)_1fr_auto]"
            data-testid="runtime-row"
        >
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <span class="font-mono text-[13.5px] font-medium">{row.label}</span>
                {#if row.status.label}
                    <Badge variant={row.status.tone}>{row.status.label}</Badge>
                {/if}
            </div>
            <p class="m-0 min-w-0 text-[12.5px] text-muted-foreground max-sm:col-span-2 max-sm:row-start-2">
                {row.applications.length === 1 ? 'Used by' : `Used by ${row.applications.length} apps:`}
                {#each row.applications as application, index (application.id)}<Link
                        href={application.href}
                        class="focus-ring rounded-sm font-mono text-foreground underline-offset-4 hover:underline"
                        >{application.name}</Link
                    >{index < row.applications.length - 1 ? ', ' : ''}{/each}.
                {#if row.eol}
                    It gets no more security fixes, so move each app to a supported version with Change on its page.
                {/if}
            </p>
            <div class="justify-self-end text-right max-sm:col-start-2 max-sm:row-start-1">
                {#if row.newest && runtimes.can_update && movable(row).length > 0}
                    <Button variant="outline" size="sm" disabled={installing} onclick={() => open(row)}
                        >Update to {row.newest}</Button
                    >
                {:else if row.newest && runtimes.can_update}
                    <span class="text-[12.5px] text-muted-foreground"
                        >{row.newest} is out, but every app here pins {row.version}</span
                    >
                {:else if row.newest}
                    <span class="text-[12.5px] text-muted-foreground">{row.newest} is out</span>
                {:else}
                    <span class="text-[12.5px] text-muted-foreground">Newest patch</span>
                {/if}
            </div>
        </div>
    {/each}
</section>

<Dialog.Root open={picked !== null} onOpenChange={(open) => !open && (picked = null)}>
    <Dialog.Content>
        {#if picked}
            <form onsubmit={submit}>
                <Dialog.Header>
                    <Dialog.Title>Update {picked.label} to {picked.newest}?</Dialog.Title>
                    <Dialog.Description>
                        Coresmith installs {picked.name}
                        {picked.newest} on {server}, then deploys the apps you tick again, from their branches.
                    </Dialog.Description>
                </Dialog.Header>
                <fieldset class="mt-4 flex flex-col gap-1">
                    <legend class="type-label mb-1.5">Apps to deploy</legend>
                    {#each picked.applications as application (application.id)}
                        <div class="flex min-h-10 items-start gap-2.5 py-1">
                            <Checkbox
                                id="runtime-app-{application.id}"
                                bind:checked={chosen[application.id]}
                                disabled={application.pinned !== null || !application.deployable}
                                class="mt-0.5"
                            />
                            <div class="min-w-0">
                                <Label for="runtime-app-{application.id}" class="font-mono font-normal"
                                    >{application.name}</Label
                                >
                                {#if application.pinned}
                                    <p class="m-0 text-[12px] text-muted-foreground">
                                        Stays on {picked.version}, since its {application.pinned} asks for exactly that version.
                                    </p>
                                {:else if !application.deployable}
                                    <p class="m-0 text-[12px] text-muted-foreground">
                                        Your role can't deploy this app.
                                    </p>
                                {/if}
                            </div>
                        </div>
                    {/each}
                </fieldset>
                <FieldError message={error} />
                <p class="mt-4 mb-0 rounded-md border border-border px-3.5 py-3 text-[12.5px] text-muted-foreground">
                    A patch rarely changes how an app behaves, but it can break one. Each deploy is checked before it
                    goes live, so an app that fails keeps serving its current release. Commits on a branch that aren't
                    live yet go out too.
                </p>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (picked = null)}>Cancel</Button>
                    <Button type="submit" disabled={working || !Object.values(chosen).some(Boolean)}
                        >Update and deploy</Button
                    >
                </Dialog.Footer>
            </form>
        {/if}
    </Dialog.Content>
</Dialog.Root>
