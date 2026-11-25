<script lang="ts" generics="T extends Switchable">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Link, router } from '@inertiajs/svelte'
    import SectionCard from '@/components/SectionCard.svelte'
    import { Button, buttonVariants } from '@/components/ui/button'
    import { Switch } from '@/components/ui/switch'
    import type { ProcessRow, Switchable } from '@/lib/app-page'
    import { cn } from '@/lib/utils'

    let {
        processes,
        toggles,
        busy,
        onflip,
        canUpdate,
        restartAllHref,
        app,
        server
    }: {
        processes: ProcessRow[]
        toggles: T[]
        busy: Record<string, boolean>
        onflip: (toggle: T, on: boolean) => void
        canUpdate: boolean
        restartAllHref: string | null
        app: string
        server: string
    } = $props()

    let restarting = $state<string | null>(null)

    const dots: Record<string, string> = {
        success: 'bg-success',
        warning: 'bg-warning',
        destructive: 'bg-destructive',
        accent: 'bg-primary',
        default: 'bg-muted-foreground/50'
    }
    const words: Record<string, string> = {
        success: 'text-foreground',
        warning: 'text-warning-strong',
        destructive: 'text-destructive-strong',
        accent: 'text-primary-strong',
        default: 'text-muted-foreground'
    }

    function toggleFor(key: string | null): T | null {
        return key === null ? null : (toggles.find((toggle) => toggle.key === key) ?? null)
    }

    function shown(row: ProcessRow, toggle: T | null): { label: string; tone: string } {
        if (toggle?.failure) {
            return { label: 'Did not start', tone: 'destructive' }
        }

        if (toggle && busy[toggle.key]) {
            return { label: toggle.on ? toggle.busy_off : toggle.busy_on, tone: 'accent' }
        }

        if (toggle?.starting) {
            return { label: 'Starting', tone: 'accent' }
        }

        return row.state ?? { label: 'Waiting for the first reading', tone: 'default' }
    }

    function restart(href: string, key: string) {
        router.post(
            href,
            {},
            { preserveScroll: true, onStart: () => (restarting = key), onFinish: () => (restarting = null) }
        )
    }
</script>

{#snippet description()}
    What runs for <span class="font-mono">{app}</span> on <span class="font-mono">{server}</span>, read every minute.
    Switches take effect at once, without a deploy.
{/snippet}

{#snippet restartAll()}
    {#if restartAllHref}
        {@const href = restartAllHref}
        <Button variant="outline" size="sm" disabled={restarting !== null} onclick={() => restart(href, '*')}
            >{restarting === '*' ? 'Restarting…' : 'Restart all'}</Button
        >
    {/if}
{/snippet}

<SectionCard
    title="Processes"
    titleId="processes-title"
    level={2}
    {description}
    actions={restartAllHref ? restartAll : undefined}
    testid="processes-card"
>
    {#if processes.length === 0}
        <p class="m-0 border-t border-border px-5.5 py-4 text-[13px] text-muted-foreground max-sm:px-4">
            Nothing runs until the first deploy, and a static site's files are served as they are.
        </p>
    {:else}
        <table class="w-full border-collapse text-[13.5px] max-sm:block">
            <thead class="max-sm:hidden">
                <tr class="border-t border-border text-left text-[12px] tracking-[0.02em] text-muted-foreground">
                    <th scope="col" class="py-2 pr-3 pl-5.5 font-medium">Process</th>
                    <th scope="col" class="px-3 py-2 font-medium">On</th>
                    <th scope="col" class="px-3 py-2 font-medium">State</th>
                    <th scope="col" class="px-3 py-2 font-medium">Memory</th>
                    <th scope="col" class="px-3 py-2 font-medium">Started</th>
                    <th scope="col" class="py-2 pr-5.5 pl-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="max-sm:block">
                {#each processes as row (row.key)}
                    {@const toggle = toggleFor(row.toggle)}
                    {@const now = shown(row, toggle)}
                    <tr
                        class="border-t border-border align-middle max-sm:grid max-sm:grid-cols-[minmax(0,1fr)_auto] max-sm:items-center max-sm:gap-x-3 max-sm:gap-y-2 max-sm:px-4 max-sm:py-3"
                        data-testid={toggle ? `toggle-${toggle.key}` : `process-${row.key}`}
                    >
                        <td class="py-3 pr-3 pl-5.5 max-sm:p-0">
                            <div class="flex min-w-0 items-start gap-2.5">
                                <span
                                    class={cn(
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        dots[now.tone] ?? dots.default,
                                        now.tone === 'warning' && 'status-dot-pulse',
                                        now.tone === 'accent' && 'status-dot-working'
                                    )}
                                    aria-hidden="true"
                                ></span>
                                <div class="min-w-0">
                                    <div class="font-semibold" id="process-{row.key}-label">{row.label}</div>
                                    {#if row.description}
                                        <div
                                            class="text-[12.5px] wrap-anywhere text-muted-foreground"
                                            id="process-{row.key}-description"
                                        >
                                            {row.description}
                                        </div>
                                    {/if}
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3 max-sm:col-start-2 max-sm:row-start-1 max-sm:p-0">
                            {#if toggle}
                                <Switch
                                    checked={toggle.on}
                                    busy={busy[toggle.key]}
                                    disabled={!canUpdate || toggle.disabled || busy[toggle.key]}
                                    aria-labelledby="process-{row.key}-label"
                                    aria-describedby={row.description ? `process-${row.key}-description` : undefined}
                                    onCheckedChange={(value) => onflip(toggle, value)}
                                />
                            {:else}
                                <span class="text-[12px] text-muted-foreground">Always</span>
                            {/if}
                        </td>
                        <td class="px-3 py-3 max-sm:col-span-2 max-sm:p-0 max-sm:pl-4.5">
                            <span class={cn('text-[13px]', words[now.tone] ?? words.default)}>{now.label}</span>
                            {#if row.memory || row.since}
                                <span class="text-[12px] text-muted-foreground sm:hidden"
                                    >{` · ${[row.memory, row.since && `since ${row.since}`].filter(Boolean).join(' · ')}`}</span
                                >
                            {/if}
                        </td>
                        <td
                            class="px-3 py-3 font-mono text-[12.5px] whitespace-nowrap text-muted-foreground max-sm:hidden"
                            >{row.memory ?? ''}</td
                        >
                        <td class="px-3 py-3 text-[12.5px] whitespace-nowrap text-muted-foreground max-sm:hidden"
                            >{row.since ?? ''}</td
                        >
                        <td
                            class="py-3 pr-5.5 pl-3 text-right max-sm:col-span-2 max-sm:p-0 max-sm:pl-4.5 max-sm:text-left"
                        >
                            <div class="inline-flex gap-1.5 max-sm:flex max-sm:gap-2">
                                {#if row.restart_href}
                                    {@const href = row.restart_href}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="max-sm:min-h-11 max-sm:flex-1"
                                        disabled={restarting !== null}
                                        onclick={() => restart(href, row.key)}
                                        >{restarting === row.key ? 'Restarting…' : 'Restart'}<span class="sr-only">
                                            {row.label}</span
                                        ></Button
                                    >
                                {/if}
                                {#if row.log_href}
                                    <Link
                                        href={row.log_href}
                                        class={cn(
                                            buttonVariants({ variant: 'ghost', size: 'sm' }),
                                            'max-sm:min-h-11 max-sm:flex-1'
                                        )}>Log<span class="sr-only"> of {row.label}</span></Link
                                    >
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    {/if}
</SectionCard>
