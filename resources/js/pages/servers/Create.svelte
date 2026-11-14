<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Deferred, Form, router } from '@inertiajs/svelte'
    import { TriangleAlert } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import CloudConnectFields from '@/components/cloud/CloudConnectFields.svelte'
    import CloudServerFields from '@/components/cloud/CloudServerFields.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { CloudCatalog, CloudChoices, CloudPicks } from '@/lib/cloud'

    let {
        teams = [],
        current = null,
        environments = [],
        phases = [],
        cloud,
        catalog,
        thisMachine = null
    }: {
        teams: { id: string; name: string; limit: string | null }[]
        current?: string | null
        environments: string[]
        phases: { name: string; desc: string }[]
        cloud: CloudChoices
        catalog?: CloudCatalog | null
        thisMachine?: { installed: boolean; taken_by: string | null; name: string; installer: string } | null
    } = $props()

    let picked = $state<string | null>(null)
    let team = $state<string | null>(null)

    const environmentOptions = $derived([{ value: '', label: 'No environment' }, ...environments])
    const chosenTeam = $derived(team ?? current ?? teams[0]?.id ?? null)
    const note = $derived(teams.find((row) => row.id === chosenTeam)?.limit ?? null)
    const providerNote = $derived(teams.find((row) => row.id === (current ?? teams[0]?.id))?.limit ?? null)
    let picks = $state<CloudPicks>({})
    let typedName = $state<string | null>(null)

    const options = $derived([
        ...(thisMachine ? [{ value: 'this-machine', label: 'This machine' }] : []),
        ...cloud.accounts.map((row) => ({
            value: `account:${row.id}`,
            label: row.refused ? `${row.name}, token refused` : row.name
        })),
        ...cloud.types.map((type) => ({ value: `connect:${type.value}`, label: `${type.label}, not connected yet` })),
        { value: 'manual', label: 'Bring your own VM' }
    ])
    const fallback = $derived(
        thisMachine !== null && thisMachine.taken_by === null && cloud.selected === 'manual'
            ? 'this-machine'
            : cloud.selected
    )
    const choice = $derived(picked !== null && options.some((option) => option.value === picked) ? picked : fallback)
    const name = $derived(typedName ?? (choice === 'this-machine' && thisMachine ? thisMachine.name : ''))
    const account = $derived(cloud.accounts.find((row) => `account:${row.id}` === choice) ?? null)
    const connecting = $derived(cloud.types.find((type) => `connect:${type.value}` === choice) ?? null)

    function choose(value: string) {
        if (value !== choice) {
            picks = {}
        }

        picked = value

        if (value.startsWith('account:')) {
            router.get(
                route('servers.create', { account: value.slice('account:'.length) }),
                {},
                { preserveState: true, preserveScroll: true, replace: true }
            )
        }
    }
</script>

{#snippet serverName(error: string | undefined)}
    <div>
        <Label for="name" class="mb-1.5">Server name</Label>
        <Input
            id="name"
            name="name"
            required
            placeholder="outpost-02"
            autocomplete="off"
            value={name}
            oninput={(event) => (typedName = (event.currentTarget as HTMLInputElement).value)}
            aria-invalid={error ? true : undefined}
        />
        <FieldError message={error} />
    </div>
{/snippet}

{#snippet providerSelect()}
    <div>
        <Label for="provider" class="mb-1.5">Provider</Label>
        <NativeSelect
            id="provider"
            {options}
            value={choice}
            onchange={(event) => choose((event.currentTarget as HTMLSelectElement).value)}
        />
    </div>
{/snippet}

{#snippet teamField(error: string | undefined)}
    {#if teams.length > 1}
        <div>
            <Label for="team_id" class="mb-1.5">Team</Label>
            <NativeSelect
                id="team_id"
                name="team_id"
                options={teams.map((row) => ({ value: row.id, label: row.name }))}
                value={chosenTeam ?? undefined}
                onchange={(event) => (team = (event.currentTarget as HTMLSelectElement).value)}
            />
        </div>
    {:else}
        <input type="hidden" name="team_id" value={chosenTeam ?? ''} />
    {/if}
    <FieldError message={error} />
{/snippet}

{#snippet limitBanner(text: string)}
    <div
        class="tint-warning flex items-start gap-3 rounded-xl border px-4.5 py-3 max-sm:px-3.5"
        role="status"
        data-testid="server-limit"
    >
        <TriangleAlert class="mt-0.5 size-4.5 shrink-0 text-warning max-sm:size-4" />
        <span class="min-w-0 flex-1 text-[13.5px] max-sm:text-[12.5px]">{text}</span>
    </div>
{/snippet}

<AppHead title="Provision a server" />

<BackLink href={route('dashboard')} label="Fleet" />

<h1 class="type-h1 m-0 mb-1.5">Provision a <MarkerUnderline>server</MarkerUnderline></h1>
<p class="type-body-sm mb-7 text-muted-foreground">
    About six minutes from blank VM to hardened, ready-to-deploy host. Every phase is logged.
</p>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card p-6">
        {#if choice === 'this-machine' && thisMachine}
            <Form method="post" action={route('servers.this-machine.store')}>
                {#snippet children({ errors, processing })}
                    <div class="flex flex-col gap-4">
                        {@render serverName(errors.name)}
                        {@render providerSelect()}
                        {#if thisMachine.taken_by !== null}
                            <p class="m-0 text-[12.5px] text-muted-foreground" data-testid="this-machine-taken">
                                This machine is already a server in {thisMachine.taken_by}.
                            </p>
                        {:else if !thisMachine.installed}
                            <p class="m-0 text-[12.5px] text-muted-foreground" data-testid="this-machine-installer">
                                This install's installer is from before Coresmith could use its own machine. Run it
                                again on this machine, then come back.
                            </p>
                            <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
                            <pre
                                class="terminal text-[11.5px] leading-[1.7] wrap-anywhere whitespace-pre-wrap"
                                tabindex="0"
                                role="region"
                                aria-label="Command that runs the installer again">{thisMachine.installer}</pre>
                        {:else}
                            <p class="m-0 text-[12.5px] text-muted-foreground" data-testid="this-machine-explained">
                                Run your apps on the machine Coresmith runs on. Coresmith gets root here with a key that
                                only works from this machine, then checks and provisions it like any server.
                            </p>
                            <div>
                                <Label for="environment" class="mb-1.5">Environment</Label>
                                <NativeSelect
                                    id="environment"
                                    name="environment"
                                    options={environmentOptions}
                                    value=""
                                    aria-invalid={errors.environment ? true : undefined}
                                />
                                <FieldError message={errors.environment} />
                            </div>
                            {@render teamField(errors.team_id)}
                            <Button type="submit" class="w-full" disabled={processing}>Use this machine</Button>
                        {/if}
                    </div>
                {/snippet}
            </Form>
        {:else if account}
            <Form method="post" action={route('servers.cloud.store')}>
                {#snippet children({ errors, processing })}
                    <div class="flex flex-col gap-4">
                        {@render serverName(errors.name)}
                        {@render providerSelect()}
                        <input type="hidden" name="cloud_provider_id" value={account.id} />
                        <FieldError message={errors.cloud_provider_id} />
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
                                        {#if providerNote}
                                            {@render limitBanner(providerNote)}
                                        {/if}
                                        <Button
                                            type="submit"
                                            class="w-full"
                                            disabled={processing || providerNote !== null}>Provision server</Button
                                        >
                                    {/if}
                                </Deferred>
                            {/key}
                        {/if}
                    </div>
                {/snippet}
            </Form>
        {:else if connecting}
            <Form method="post" action={route('cloud-providers.store')}>
                {#snippet children({ errors, processing })}
                    <div class="flex flex-col gap-4">
                        {@render providerSelect()}
                        <input type="hidden" name="type" value={connecting.value} />
                        <input type="hidden" name="name" value={connecting.label} />
                        <input type="hidden" name="return_to" value="provision" />
                        {#if cloud.can_connect}
                            <CloudConnectFields type={connecting} error={errors.api_token} />
                            <FieldError message={errors.type ?? errors.name} />
                            <Button type="submit" class="w-full" disabled={processing}
                                >Connect {connecting.label}</Button
                            >
                        {:else}
                            <p class="m-0 text-[12.5px] text-muted-foreground">
                                An owner or admin can connect a provider.
                            </p>
                        {/if}
                    </div>
                {/snippet}
            </Form>
        {:else}
            <Form method="post" action={route('servers.store')}>
                {#snippet children({ errors, processing })}
                    <div class="flex flex-col gap-4">
                        {@render serverName(errors.name)}
                        {@render providerSelect()}
                        <div>
                            <Label for="ip_address" class="mb-1.5">IP address</Label>
                            <Input
                                id="ip_address"
                                name="ip_address"
                                required
                                placeholder="203.0.113.40"
                                autocomplete="off"
                                aria-invalid={errors.ip_address ? true : undefined}
                            />
                            <FieldError message={errors.ip_address} />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <Label for="ssh_port" class="mb-1.5">SSH port</Label>
                                <Input
                                    id="ssh_port"
                                    name="ssh_port"
                                    type="number"
                                    value="22"
                                    min="1"
                                    max="65535"
                                    required
                                    aria-invalid={errors.ssh_port ? true : undefined}
                                />
                                <FieldError message={errors.ssh_port} />
                            </div>
                            <div>
                                <Label for="ssh_user" class="mb-1.5">SSH user</Label>
                                <Input
                                    id="ssh_user"
                                    name="ssh_user"
                                    value="root"
                                    autocomplete="off"
                                    spellcheck="false"
                                    required
                                    aria-invalid={errors.ssh_user ? true : undefined}
                                />
                                <FieldError message={errors.ssh_user} />
                            </div>
                        </div>
                        <div>
                            <Label for="password" class="mb-1.5">Password, optional</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                aria-describedby="password-hint"
                                aria-invalid={errors.password ? true : undefined}
                            />
                            <FieldError message={errors.password} />
                            <p id="password-hint" class="mt-1.5 text-[12px] text-muted-foreground">
                                The SSH user's password. Coresmith uses it once to add its own key, then forgets it.
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <Label for="environment" class="mb-1.5">Environment</Label>
                                <NativeSelect
                                    id="environment"
                                    name="environment"
                                    options={environmentOptions}
                                    value=""
                                    aria-invalid={errors.environment ? true : undefined}
                                />
                                <FieldError message={errors.environment} />
                            </div>
                            <div>
                                <Label for="region" class="mb-1.5">Region</Label>
                                <Input
                                    id="region"
                                    name="region"
                                    placeholder="fra1"
                                    aria-invalid={errors.region ? true : undefined}
                                />
                                <FieldError message={errors.region} />
                            </div>
                        </div>
                        {@render teamField(errors.team_id)}
                        <p class="-mt-1 text-[12px] text-muted-foreground">
                            Needs SSH access and Ubuntu, Debian, Rocky Linux, AlmaLinux, Fedora or Amazon Linux, with
                            Ubuntu 24.04 and Debian 12 tested most. Coresmith makes a key for this server alone. Without
                            a password, you run one command to add it.
                        </p>
                        {#if note}
                            {@render limitBanner(note)}
                        {/if}
                        <Button type="submit" class="w-full" disabled={processing || note !== null}>Add server</Button>
                    </div>
                {/snippet}
            </Form>
        {/if}
    </div>

    <div class="rounded-xl border border-border bg-card p-6">
        <h3 class="type-h3 m-0 mb-1">What happens next</h3>
        <p class="mb-4 text-[12.5px] text-muted-foreground">
            Coresmith runs eight phases and hardens the server along the way.
        </p>
        <ol class="m-0 flex list-none flex-col gap-0.5 p-0">
            {#each phases as phase, index (phase.name)}
                <li class="flex items-baseline gap-3 py-1.75">
                    <span class="w-4 flex-none font-mono text-[12px] text-primary-strong">{index + 1}</span>
                    <span class="text-[13.5px]"
                        ><span class="font-medium">{phase.name}</span><span class="text-muted-foreground"
                            >{`, ${phase.desc}`}</span
                        ></span
                    >
                </li>
            {/each}
        </ol>
    </div>
</div>
