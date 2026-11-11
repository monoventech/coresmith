<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, router } from '@inertiajs/svelte'
    import StateDot from '@/components/StateDot.svelte'
    import { Button } from '@/components/ui/button'
    import { toneText } from '@/lib/fleet'
    import type { EnvironmentView, Place } from '@/lib/projects'
    import { ENGINES } from '@/lib/projects'
    import { cn } from '@/lib/utils'

    let {
        environment,
        manage,
        onfill,
        onrename,
        ondelete
    }: {
        environment: EnvironmentView
        manage: boolean
        onfill: () => void
        onrename: () => void
        ondelete: () => void
    } = $props()

    let removing = $state<string | null>(null)

    const empty = $derived(environment.applications.length === 0 && environment.databases.length === 0)
    const row =
        'flex min-h-13 flex-wrap items-center gap-x-3 gap-y-1 border-t border-border px-5.5 py-2 text-[13.5px] first:border-t-0 max-sm:px-4'

    function takeOut(href: string) {
        removing = href
        router.delete(href, { preserveScroll: true, onFinish: () => (removing = null) })
    }
</script>

{#snippet where(server: Place)}
    on {#if server.href}<Link href={server.href} class="focus-ring rounded-sm font-mono text-muted-foreground"
            >{server.name}</Link
        >{:else}<span class="font-mono">{server.name}</span>{/if}
{/snippet}

<section
    class="overflow-hidden rounded-xl border border-border bg-card"
    aria-labelledby="environment-{environment.id}"
    data-testid="environment"
>
    <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-5.5 py-4 max-sm:px-4">
        <div class="min-w-0 flex-1">
            <h2 id="environment-{environment.id}" class="m-0 text-[15px] font-medium wrap-anywhere">
                {environment.name}
            </h2>
            <p class="m-0 mt-0.5 text-[12.5px] text-muted-foreground">{environment.meta}</p>
        </div>
        <div class="flex flex-wrap gap-1.5">
            {#if environment.can.fill}
                <Button variant="outline" size="sm" onclick={onfill}>Add apps or databases</Button>
            {/if}
            {#if manage}
                <Button variant="ghost" size="sm" onclick={onrename} aria-label="Rename {environment.name}"
                    >Rename</Button
                >
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive-strong"
                    onclick={ondelete}
                    aria-label="Delete {environment.name}">Delete</Button
                >
            {/if}
        </div>
    </header>

    {#if empty}
        <p class="m-0 px-5.5 py-5 text-[13px] text-muted-foreground max-sm:px-4">
            Nothing in {environment.name} yet.{environment.can.fill
                ? ` Add an app or a database, or pick ${environment.name} when you create one.`
                : ''}
        </p>
    {:else}
        <ul class="m-0 list-none p-0" aria-label="In {environment.name}">
            {#each environment.applications as application (application.id)}
                <li class={row} data-testid="environment-app">
                    <StateDot tone={application.state.tone} state={application.state.key} />
                    <div class="min-w-0 flex-1">
                        <Link
                            href={application.href}
                            class="focus-ring rounded-sm font-mono text-[14px] font-medium wrap-anywhere text-foreground"
                            >{application.name}</Link
                        >
                        <span class="text-[12.5px] text-muted-foreground">
                            · {application.kind} · {@render where(application.server)}</span
                        >
                    </div>
                    <span class={cn('text-[12px] font-medium whitespace-nowrap', toneText(application.state.tone))}
                        >{application.state.label}</span
                    >
                    {#if application.remove_href}
                        {@const href = application.remove_href}
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={removing !== null}
                            onclick={() => takeOut(href)}
                            aria-label="Take {application.name} out of {environment.name}">Take out</Button
                        >
                    {/if}
                </li>
            {/each}
            {#each environment.databases as database (database.id)}
                <li class={row} data-testid="environment-database">
                    <div class="min-w-0 flex-1">
                        <Link
                            href={database.href}
                            class="focus-ring rounded-sm font-mono text-[14px] font-medium wrap-anywhere text-foreground"
                            >{database.name}</Link
                        >
                        <span class="text-[12.5px] text-muted-foreground">
                            · {ENGINES[database.type] ?? database.type} · {@render where(database.server)}</span
                        >
                    </div>
                    {#if database.remove_href}
                        {@const href = database.remove_href}
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={removing !== null}
                            onclick={() => takeOut(href)}
                            aria-label="Take {database.name} out of {environment.name}">Take out</Button
                        >
                    {/if}
                </li>
            {/each}
        </ul>

        {#if environment.deploys.length > 0}
            <div class="border-t border-border px-5.5 pt-3.5 pb-2 max-sm:px-4">
                <h3 class="type-label m-0 mb-1 text-muted-foreground">Recent deploys</h3>
                <ul class="m-0 list-none p-0" aria-label="Recent deploys in {environment.name}">
                    {#each environment.deploys as deploy (deploy.id)}
                        <li class="flex min-h-10 items-center gap-3 text-[13px]">
                            {#if deploy.href}
                                <Link
                                    href={deploy.href}
                                    class="focus-ring min-w-0 truncate rounded-sm font-mono text-foreground"
                                    >{deploy.application} · {deploy.release}</Link
                                >
                            {:else}
                                <span class="min-w-0 truncate font-mono">{deploy.application} · {deploy.release}</span>
                            {/if}
                            <span class="text-[12.5px] whitespace-nowrap text-muted-foreground" title={deploy.full}
                                >{deploy.when}</span
                            >
                            <span
                                class={cn(
                                    'ml-auto text-[12px] font-medium whitespace-nowrap',
                                    toneText(deploy.state.tone)
                                )}>{deploy.state.label}</span
                            >
                        </li>
                    {/each}
                </ul>
            </div>
        {/if}
    {/if}
</section>
