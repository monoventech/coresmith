<script lang="ts">
    import { Deferred, Form, Link, router } from '@inertiajs/svelte'
    import { Check, GitBranch } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import CloudConnectFields from '@/components/cloud/CloudConnectFields.svelte'
    import CloudServerFields from '@/components/cloud/CloudServerFields.svelte'
    import CloudSteps from '@/components/cloud/CloudSteps.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import PhaseIcon from '@/components/PhaseIcon.svelte'
    import GitHubRegistration from '@/components/settings/GitHubRegistration.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { CloudCatalog, CloudChoices, CloudPicks } from '@/lib/cloud'
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
        create_application_href = null,
        cloud = null,
        catalog,
        environments = []
    }: {
        steps: { label: string; done: boolean }[]
        current: number
        step: number
        complete: boolean
        github: GitHubSetup | null
        team: { id: string }
        server: (ServerHeader & { href: string }) | null
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
        cloud: CloudChoices | null
        catalog?: CloudCatalog | null
        environments: string[]
    } = $props()

    let deploying = $state(false)
    let source = $state<'provider' | 'existing'>('provider')
    let picked = $state<string | null>(null)
    let picks = $state<CloudPicks>({})

    const sources = [
        {
            key: 'provider' as const,
            label: 'Provision through a provider',
            desc: 'Hetzner, DigitalOcean, Vultr or Linode, with an API token'
        },
        {
            key: 'existing' as const,
            label: 'Use an existing VM',
            desc: 'A Linux machine with SSH access, such as Ubuntu 24.04 or Debian 12'
        }
    ]

    const options = $derived([
        ...(cloud?.accounts ?? []).map((row) => ({
            value: `account:${row.id}`,
            label: row.refused ? `${row.name}, token refused` : row.name
        })),
        ...(cloud?.types ?? []).map((type) => ({
            value: `connect:${type.value}`,
            label: `${type.label}, not connected yet`
        }))
    ])
    const choice = $derived(
        picked !== null && options.some((option) => option.value === picked)
            ? picked
            : cloud?.selected.startsWith('account:')
              ? cloud.selected
              : (options.find((option) => option.value.startsWith('connect:'))?.value ?? options[0]?.value ?? '')
    )
    const account = $derived(cloud?.accounts.find((row) => `account:${row.id}` === choice) ?? null)
    const connecting = $derived(cloud?.types.find((type) => `connect:${type.value}` === choice) ?? null)
    const creation = $derived(
        server?.provisioning?.status === 'creating' && server.cloud?.task?.type === 'create' ? server.cloud : null
    )

    const provisioning = $derived(server?.provisioning ?? null)
    const elsewhere = $derived(github?.elsewhere ? new URL(github.elsewhere).host : null)

    function go(index: number) {
        router.visit(route('setup.show', { step: index }), { preserveScroll: true })
    }

    $effect(() => {
        if (step !== 2 || (creation?.task?.state !== 'running' && provisioning?.status !== 'provisioning')) {
            return
        }

        const timer = setInterval(() => router.reload({ only: ['server'] }), 3000)

        return () => clearInterval(timer)
    })

    function choose(value: string) {
        if (value !== choice) {
            picks = {}
        }

        picked = value

        if (value.startsWith('account:')) {
            router.get(
                route('setup.show', { step: 1, account: value.slice('account:'.length) }),
                {},
                { preserveState: true, preserveScroll: true, replace: true }
            )
        }
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

{#snippet onward(variant: 'default' | 'outline' = 'outline')}
    {#if current > 0}
        <Button {variant} onclick={() => go(1)}>Continue</Button>
    {/if}
{/snippet}

<h1 class="type-h1 m-0 mb-1.5">Set up <MarkerUnderline>Coresmith</MarkerUnderline></h1>
<p class="type-body-sm mb-7 text-muted-foreground">
    {complete
        ? 'Every step is done. The Setup link has gone, and this page stays here for reference.'
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
                {#if github?.connected}<Badge variant="success">Connected</Badge>{/if}
            </div>
            <p class="type-body-sm mb-4 text-muted-foreground">
                Install the Coresmith GitHub App on your account or organization, on the repositories you choose. It
                asks only for read-only contents, commit statuses and webhooks.
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
            {#if github?.connected}
                <div class="flex flex-wrap items-center gap-3">
                    {@render onward('default')}
                    <span class="text-[12.5px] text-muted-foreground"
                        >Connected as <span class="font-mono text-[12px]">{github.name}</span></span
                    >
                </div>
            {:else if github?.connect_href}
                <div class="flex flex-wrap items-center gap-3">
                    <Button href={github.connect_href} target="_self">Connect GitHub</Button>
                    {@render onward()}
                </div>
            {:else if github?.registration}
                <GitHubRegistration
                    registration={github.registration}
                    compact
                    submitLabel="Connect GitHub"
                    actions={onward}
                />
            {:else if github?.elsewhere}
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Create the app from <span class="font-mono text-[12px]">{elsewhere}</span>, GitHub sends you back
                    there.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <Button href={github.elsewhere}>Open {elsewhere}</Button>
                    {@render onward()}
                </div>
            {:else if github && !github.can_manage}
                <p class={cn('text-[12.5px] text-muted-foreground', current > 0 ? 'mb-3' : 'm-0')}>
                    An owner or admin connects GitHub.
                </p>
                {@render onward('default')}
            {/if}
        </div>
    {:else if step === 1}
        <div class="rounded-xl border border-border bg-card p-6" data-testid="setup-step">
            <h3 class="type-h3 m-0 mb-1.5">Add your first server</h3>
            <p class="type-body-sm mb-4 text-muted-foreground">
                Provision a fresh VM through a provider, or point Coresmith at a machine you already have.
            </p>
            <div
                class="mb-4 grid grid-cols-2 gap-3 max-sm:grid-cols-1"
                role="group"
                aria-label="Where the first server comes from"
            >
                {#each sources as option (option.key)}
                    <button
                        type="button"
                        class={cn(
                            'focus-ring cursor-pointer rounded-md border p-4 text-left',
                            source === option.key ? 'option-selected' : 'border-border bg-transparent'
                        )}
                        aria-pressed={source === option.key}
                        onclick={() => (source = option.key)}
                    >
                        <div class="mb-1 text-[14px] font-medium">{option.label}</div>
                        <div class="text-[12.5px] text-muted-foreground">{option.desc}</div>
                    </button>
                {/each}
            </div>
            {#snippet providerSelect()}
                <div>
                    <Label for="setup-provider" class="mb-1.5">Provider</Label>
                    <NativeSelect
                        id="setup-provider"
                        {options}
                        value={choice}
                        onchange={(event) => choose((event.currentTarget as HTMLSelectElement).value)}
                    />
                </div>
            {/snippet}
            {#if server}
                <p class="mb-4 text-[13px]">
                    <span class="font-mono">{server.name}</span> is already added.
                    <Link href={server.href} class="focus-ring rounded-sm font-medium text-primary-strong no-underline"
                        >Open it</Link
                    >
                </p>
                <div class="flex gap-3">
                    <Button variant="outline" onclick={() => go(0)}>Back</Button>
                    <Button onclick={() => go(2)}>Continue</Button>
                </div>
            {:else if source === 'provider' && cloud && account}
                <Form method="post" action={route('servers.cloud.store')}>
                    {#snippet children({ errors, processing })}
                        <input type="hidden" name="cloud_provider_id" value={account.id} />
                        <input type="hidden" name="return_to" value="setup" />
                        <div class="mb-5 flex flex-col gap-3.5">
                            {@render providerSelect()}
                            <FieldError message={errors.cloud_provider_id} />
                            <div>
                                <Label for="setup-name" class="mb-1.5">Server name</Label>
                                <Input
                                    id="setup-name"
                                    name="name"
                                    required
                                    placeholder="forge-01"
                                    autocomplete="off"
                                    aria-invalid={errors.name ? true : undefined}
                                />
                                <FieldError message={errors.name} />
                            </div>
                            {#snippet loading()}
                                <p class="m-0 text-[12.5px] text-muted-foreground">
                                    Loading {account.provider}'s regions and sizes.
                                </p>
                            {/snippet}
                            {#if cloud.selected !== choice}
                                {@render loading()}
                            {:else}
                                {#key choice}
                                    <Deferred data="catalog" fallback={loading}>
                                        {#if catalog?.error}
                                            <div
                                                class="tint-destructive rounded-lg border px-3.5 py-3 text-[12.5px] leading-normal"
                                                role="alert"
                                            >
                                                <p class="m-0">{catalog.error}</p>
                                                {#if account.refused}
                                                    <Button
                                                        class="mt-2.5"
                                                        variant="outline"
                                                        size="sm"
                                                        href={route('teams.index')}>Open team settings</Button
                                                    >
                                                {:else}
                                                    <Button
                                                        class="mt-2.5"
                                                        variant="outline"
                                                        size="sm"
                                                        onclick={() => router.reload({ only: ['catalog'] })}
                                                        >Try again</Button
                                                    >
                                                {/if}
                                            </div>
                                        {:else if catalog}
                                            <CloudServerFields {catalog} {environments} {errors} bind:picks />
                                        {/if}
                                    </Deferred>
                                {/key}
                            {/if}
                        </div>
                        <div class="flex gap-3">
                            <Button variant="outline" type="button" onclick={() => go(0)}>Back</Button>
                            <Button type="submit" disabled={processing || !catalog || catalog.error !== null}
                                >Continue</Button
                            >
                        </div>
                    {/snippet}
                </Form>
            {:else if source === 'provider' && cloud && connecting}
                <Form method="post" action={route('cloud-providers.store')}>
                    {#snippet children({ errors, processing })}
                        <input type="hidden" name="type" value={connecting.value} />
                        <input type="hidden" name="name" value={connecting.label} />
                        <input type="hidden" name="return_to" value="setup" />
                        <div class="mb-5 flex flex-col gap-3.5">
                            {@render providerSelect()}
                            {#if cloud.can_connect}
                                <CloudConnectFields type={connecting} error={errors.api_token} id="setup-api-token" />
                                <FieldError message={errors.type ?? errors.name} />
                            {:else}
                                <p class="m-0 text-[12.5px] text-muted-foreground">
                                    An owner or admin can connect a provider.
                                </p>
                            {/if}
                        </div>
                        <div class="flex gap-3">
                            <Button variant="outline" type="button" onclick={() => go(0)}>Back</Button>
                            <Button type="submit" disabled={processing || !cloud.can_connect}>Continue</Button>
                        </div>
                    {/snippet}
                </Form>
            {:else}
                <Form method="post" action={route('servers.store')}>
                    {#snippet children({ errors, processing })}
                        <input type="hidden" name="team_id" value={team.id} />
                        <input type="hidden" name="ssh_port" value="22" />
                        <input type="hidden" name="ssh_user" value="root" />
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
                                    Needs root SSH access and Ubuntu, Debian, Rocky Linux, AlmaLinux, Fedora or Amazon
                                    Linux. Coresmith hardens it during provisioning.
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
            {#if server && provisioning && creation?.task}
                <div class="mb-1.5 flex flex-wrap items-center gap-3">
                    <h3 class="type-h3 m-0">Creating {server.name} at {creation.provider}</h3>
                    {#if creation.task.state === 'failed'}
                        <Badge variant="destructive" dot>Failed at step {creation.task.position}</Badge>
                    {:else}
                        <Badge variant="accent" dot pulse>Step {creation.task.position} of {creation.task.total}</Badge>
                    {/if}
                </div>
                <p class="type-body-sm mb-4 text-muted-foreground">
                    {creation.provider} is creating it. Provisioning starts by itself once Coresmith can sign in.
                </p>
                <CloudSteps steps={creation.task.steps} />
                {#if creation.task.state === 'failed' && creation.task.error}
                    <div
                        class="tint-destructive mt-3 rounded-lg border px-3.5 py-3 text-[12.5px] leading-normal"
                        data-testid="creation-error"
                    >
                        {creation.task.error}
                    </div>
                {/if}
                <div class="mt-3 mb-5 flex flex-col gap-0.5">
                    {#each provisioning.phases as phase (phase.name)}
                        <div class="flex items-center gap-3 py-1.5 text-muted-foreground">
                            <PhaseIcon state="pending" />
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
            {:else if server && provisioning}
                <div class="mb-1.5 flex flex-wrap items-center gap-3">
                    <h3 class="type-h3 m-0">Provisioning {server.name}</h3>
                    {#if provisioning.status === 'provisioning'}
                        <Badge variant="accent" dot pulse>Phase {Math.min(8, provisioning.completed + 1)} of 8</Badge>
                    {:else if provisioning.status === 'failed'}
                        <Badge variant="destructive" dot>Failed</Badge>
                    {:else if provisioning.connected}
                        <Badge variant="success" dot>Connected</Badge>
                    {:else}
                        <Badge variant="default">Waiting for its key</Badge>
                    {/if}
                </div>
                <p class="type-body-sm mb-4 text-muted-foreground">
                    About six minutes, hardening included. You can watch every phase on the server page.
                </p>
                <div class="mb-5 flex flex-col gap-0.5">
                    {#each provisioning.phases as phase (phase.name)}
                        <div
                            class={cn(
                                'flex items-center gap-3 py-1.5',
                                phase.state === 'pending' && 'text-muted-foreground'
                            )}
                        >
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
                Pick a repository. Coresmith builds it into a release and serves it.
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
                        {application.runtime} · deploys to {application.server}<br />Restarted if it stops · served on
                        its domain · a new release per deploy
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
                        <Button href={create_application_href}>Add an app</Button>
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
