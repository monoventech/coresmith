<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte'
    import { Check, GitBranch } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import PhaseIcon from '@/components/PhaseIcon.svelte'
    import GitHubRegistration from '@/components/settings/GitHubRegistration.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { GitHubSetup } from '@/lib/github'
    import type { ServerHeader } from '@/lib/servers'
    import { cn } from '@/lib/utils'

    let {
        steps = [],
        current,
        step,
        complete,
        github,
        team,
        server = null,
        application = null,
        create_application_href = null
    }: {
        steps: { label: string; done: boolean }[]
        current: number
        step: number
        complete: boolean
        github: GitHubSetup
        team: { id: string }
        server: (ServerHeader & { href: string; test_href: string; provision_href: string }) | null
        application: {
            id: string
            name: string
            repository: string | null
            kind: string
            runtime: string
            server: string | null
            deploy_href: string
        } | null
        create_application_href: string | null
    } = $props()

    let deploying = $state(false)

    const provisioning = $derived(server?.provisioning ?? null)
    const elsewhere = $derived(github.elsewhere === null ? null : new URL(github.elsewhere).host)

    function go(index: number) {
        router.visit(route('setup.show', { step: index }), { preserveScroll: true })
    }

    function deploy() {
        if (!application) {
            return
        }

        deploying = true
        router.post(application.deploy_href, { from: 'setup' }, { onFinish: () => (deploying = false) })
    }
</script>

<AppHead title="Set up Coresmith" />

<h1 class="type-h1 m-0 mb-1.5">Set up <MarkerUnderline>Coresmith</MarkerUnderline></h1>
<p class="type-body-sm mb-7 text-muted-foreground">
    {complete
        ? 'Every step is done. This page stays here for reference, the sidebar item is gone.'
        : 'Four steps from a fresh install to your first deploy. You can leave and pick this up anytime.'}
</p>

<nav class="mb-6 flex flex-wrap items-center gap-1" aria-label="Setup steps">
    {#each steps as item, index (item.label)}
        {@const clickable = index <= current}
        {@const isCurrent = index === step}
        <button
            type="button"
            class={cn(
                'focus-ring inline-flex items-center gap-2 rounded-md bg-transparent px-1.5 py-1',
                clickable ? 'cursor-pointer' : 'cursor-default opacity-55'
            )}
            disabled={!clickable}
            aria-current={isCurrent ? 'step' : undefined}
            onclick={() => clickable && go(index)}
        >
            <span
                class={cn(
                    'inline-flex size-6.5 items-center justify-center rounded-md border font-mono text-[12.5px] font-semibold',
                    item.done && !isCurrent && 'border-primary bg-primary text-primary-foreground',
                    isCurrent && 'border-primary bg-accent text-foreground',
                    !item.done && !isCurrent && 'border-border bg-transparent text-muted-foreground'
                )}
            >
                {#if item.done && !isCurrent}<Check class="size-3.5" />{:else}{index + 1}{/if}
            </span>
            <span
                class={cn(
                    'text-[13px] font-medium',
                    item.done || isCurrent ? 'text-foreground' : 'text-muted-foreground'
                )}>{item.label}</span
            >
        </button>
        {#if index < steps.length - 1}
            <span class="h-px w-5 flex-none bg-border" aria-hidden="true"></span>
        {/if}
    {/each}
</nav>

<div class="max-w-165">
    {#if step === 0}
        <div class="rounded-xl border border-border bg-card p-6" data-testid="setup-step">
            <div class="mb-1.5 flex items-center gap-2.5">
                <GitBranch class="size-5" />
                <h3 class="type-h3 m-0">Connect GitHub</h3>
                {#if github.connected}<Badge variant="success">Connected</Badge>{/if}
            </div>
            <p class="type-body-sm mb-4 text-muted-foreground">
                Install the Coresmith GitHub App on your organisation. It asks for the minimum: contents read-only,
                commit statuses and webhooks, per repository, not your whole account.
            </p>
            <ul class="m-0 mb-5 flex list-none flex-col gap-2 p-0 text-[13.5px]">
                <li class="flex items-center gap-2.5">
                    <Check class="size-4 shrink-0 text-success" />Fine-grained, per-repository permissions
                </li>
                <li class="flex items-center gap-2.5">
                    <Check class="size-4 shrink-0 text-success" />Installation tokens expire after one hour
                </li>
                <li class="flex items-center gap-2.5">
                    <Check class="size-4 shrink-0 text-success" />Clones use a one-hour, read-only token for one
                    repository
                </li>
            </ul>
            {#if github.connected}
                <div class="flex flex-wrap items-center gap-3">
                    <Button onclick={() => go(1)}>Continue</Button>
                    <span class="text-[12.5px] text-muted-foreground"
                        >Connected as <span class="font-mono text-[12px]">{github.name}</span></span
                    >
                </div>
            {:else if github.connect_href}
                <Button href={github.connect_href} target="_self">Connect GitHub</Button>
            {:else if github.registration}
                <GitHubRegistration registration={github.registration} compact submitLabel="Connect GitHub" />
            {:else if github.elsewhere}
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Create the app from <span class="font-mono text-[12px]">{elsewhere}</span>, GitHub sends you back
                    there.
                </p>
                <Button href={github.elsewhere}>Open {elsewhere}</Button>
            {:else if !github.can_manage}
                <p class="m-0 text-[12.5px] text-muted-foreground">An owner or admin connects GitHub.</p>
            {/if}
        </div>
    {:else if step === 1}
        <div class="rounded-xl border border-border bg-card p-6" data-testid="setup-step">
            <h3 class="type-h3 m-0 mb-1.5">Add your first server</h3>
            <p class="type-body-sm mb-4 text-muted-foreground">
                Point Coresmith at a machine you already have. It hardens it while it provisions.
            </p>
            <div class="mb-4 grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                <div class="rounded-md border border-border p-4 opacity-55" aria-disabled="true">
                    <div class="mb-1 text-[14px] font-medium">Provision through a provider</div>
                    <div class="text-[12.5px] text-muted-foreground">
                        Hetzner, DigitalOcean or AWS via API token. Arrives with provider integrations.
                    </div>
                </div>
                <div class="option-selected rounded-md border p-4">
                    <div class="mb-1 text-[14px] font-medium">Use an existing VM</div>
                    <div class="text-[12.5px] text-muted-foreground">Any Ubuntu 24.04 machine with SSH access</div>
                </div>
            </div>
            {#if server}
                <p class="mb-4 text-[13px]">
                    <span class="font-mono">{server.name}</span> is already added.
                    <Link href={server.href} class="focus-ring rounded-sm font-medium text-primary no-underline"
                        >Open it</Link
                    >
                </p>
                <div class="flex gap-3">
                    <Button variant="outline" onclick={() => go(0)}>Back</Button>
                    <Button onclick={() => go(2)}>Continue</Button>
                </div>
            {:else}
                <Form method="post" action={route('servers.store')}>
                    {#snippet children({ errors, processing })}
                        <input type="hidden" name="team_id" value={team.id} />
                        <input type="hidden" name="ssh_port" value="22" />
                        <input type="hidden" name="ssh_user" value="root" />
                        <input type="hidden" name="environment" value="Production" />
                        <div class="mb-5 flex flex-col gap-3.5">
                            <div>
                                <Label for="setup-name" class="mb-1.5">Server name</Label>
                                <Input
                                    id="setup-name"
                                    name="name"
                                    required
                                    placeholder="forge-01"
                                    aria-invalid={errors.name ? true : undefined}
                                />
                                <FieldError message={errors.name} />
                            </div>
                            <div>
                                <Label for="setup-ip" class="mb-1.5">IP address</Label>
                                <Input
                                    id="setup-ip"
                                    name="ip_address"
                                    required
                                    placeholder="203.0.113.40"
                                    aria-invalid={errors.ip_address ? true : undefined}
                                />
                                <FieldError message={errors.ip_address} />
                                <p class="mt-1.5 text-[12px] text-muted-foreground">
                                    Needs root SSH access and Ubuntu 24.04. Coresmith hardens it during provisioning.
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <Button variant="outline" type="button" onclick={() => go(0)}>Back</Button>
                            <Button type="submit" disabled={processing}>Continue</Button>
                        </div>
                    {/snippet}
                </Form>
            {/if}
        </div>
    {:else if step === 2}
        <div class="rounded-xl border border-border bg-card p-6" data-testid="setup-step">
            {#if server && provisioning}
                <div class="mb-1.5 flex flex-wrap items-center gap-3">
                    <h3 class="type-h3 m-0">Provisioning {server.name}</h3>
                    {#if provisioning.status === 'provisioning'}
                        <Badge variant="accent" dot pulse>Phase {Math.min(8, provisioning.completed + 1)} of 8</Badge>
                    {:else if provisioning.status === 'failed'}
                        <Badge variant="destructive" dot>Failed</Badge>
                    {:else}
                        <Badge variant="default">Waiting for its key</Badge>
                    {/if}
                </div>
                <p class="type-body-sm mb-4 text-muted-foreground">
                    About six minutes. Hardening is part of provisioning, not an afterthought, you can watch every phase
                    on the server page.
                </p>
                <div class="mb-5 flex flex-col gap-0.5">
                    {#each provisioning.phases as phase (phase.name)}
                        <div class={cn('flex items-center gap-3 py-1.5', phase.state === 'pending' && 'opacity-55')}>
                            <PhaseIcon state={phase.state} />
                            <span class="text-[13.5px]"
                                ><span class="font-medium">{phase.name}</span><span class="text-muted-foreground"
                                    >{`, ${phase.desc}`}</span
                                ></span
                            >
                        </div>
                    {/each}
                </div>
                <div class="flex flex-wrap gap-3">
                    <Button variant="outline" onclick={() => go(1)}>Back</Button>
                    <Button variant="outline" href={server.href}>Open {server.name}</Button>
                    <Button disabled={current < 3} onclick={() => go(3)}>Continue while it finishes</Button>
                </div>
            {:else if server}
                <h3 class="type-h3 m-0 mb-1.5">{server.name} is provisioned</h3>
                <p class="type-body-sm mb-4 text-muted-foreground">
                    All eight phases passed. It's hardened and ready for its first app.
                </p>
                <div class="flex gap-3">
                    <Button variant="outline" onclick={() => go(1)}>Back</Button>
                    <Button onclick={() => go(3)}>Continue</Button>
                </div>
            {/if}
        </div>
    {:else}
        <div class="rounded-xl border border-border bg-card p-6" data-testid="setup-step">
            <h3 class="type-h3 m-0 mb-1.5">Deploy your first app</h3>
            <p class="type-body-sm mb-4 text-muted-foreground">
                Pick a repository. Coresmith builds it into a release and serves it through Caddy.
            </p>
            {#if application}
                <div class="mb-4 rounded-md border border-border p-4">
                    <div class="mb-2.5 flex flex-wrap items-center gap-2.5">
                        <GitBranch class="size-4" />
                        <span class="font-mono text-[14px] font-medium"
                            >{application.repository ?? application.name}</span
                        >
                        <Badge variant="accent">{application.kind}</Badge>
                    </div>
                    <div class="text-[12.5px] leading-[1.7] text-muted-foreground">
                        {application.runtime} · deploys to {application.server}<br />Process supervised by systemd ·
                        routed by Caddy · a new release per deploy
                    </div>
                </div>
                <div class="flex gap-3">
                    <Button variant="outline" onclick={() => go(2)}>Back</Button>
                    <Button disabled={deploying} onclick={deploy}>Deploy {application.name}</Button>
                </div>
            {:else}
                <div class="flex gap-3">
                    <Button variant="outline" onclick={() => go(2)}>Back</Button>
                    {#if create_application_href}
                        <Button href={create_application_href}>Create an application</Button>
                    {:else}
                        <p class="self-center text-[12.5px] text-muted-foreground">
                            Apps can be added once a server finishes provisioning.
                        </p>
                    {/if}
                </div>
            {/if}
        </div>
    {/if}
</div>
