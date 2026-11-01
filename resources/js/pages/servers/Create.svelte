<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Deferred, Form, router } from '@inertiajs/svelte'
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
        environments = [],
        phases = [],
        cloud,
        catalog
    }: {
        teams: { id: string; name: string }[]
        environments: string[]
        phases: { name: string; desc: string }[]
        cloud: CloudChoices
        catalog?: CloudCatalog | null
    } = $props()

    let picked = $state<string | null>(null)
    let picks = $state<CloudPicks>({})
    let name = $state('')

    const options = $derived([
        ...cloud.accounts.map((row) => ({
            value: `account:${row.id}`,
            label: row.refused ? `${row.name}, token refused` : row.name
        })),
        ...cloud.types.map((type) => ({ value: `connect:${type.value}`, label: `${type.label}, not connected yet` })),
        { value: 'manual', label: 'Bring your own VM' }
    ])
    const choice = $derived(
        picked !== null && options.some((option) => option.value === picked) ? picked : cloud.selected
    )
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
            bind:value={name}
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

<AppHead title="Provision a server" />

<BackLink href={route('dashboard')} label="Fleet" />

<h1 class="type-h1 m-0 mb-1.5">Provision a <MarkerUnderline>server</MarkerUnderline></h1>
<p class="type-body-sm mb-7 text-muted-foreground">
    About six minutes from blank VM to hardened, ready-to-deploy host. Every phase is logged.
</p>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card p-6">
        {#if account}
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
                                        <Button type="submit" class="w-full" disabled={processing}
                                            >Provision server</Button
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
                                    required
                                    aria-invalid={errors.ssh_user ? true : undefined}
                                />
                                <FieldError message={errors.ssh_user} />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <Label for="environment" class="mb-1.5">Environment</Label>
                                <NativeSelect
                                    id="environment"
                                    name="environment"
                                    options={environments}
                                    value="Production"
                                />
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
                        {#if teams.length > 1}
                            <div>
                                <Label for="team_id" class="mb-1.5">Team</Label>
                                <NativeSelect
                                    id="team_id"
                                    name="team_id"
                                    options={teams.map((team) => ({ value: team.id, label: team.name }))}
                                    value={teams[0]?.id}
                                />
                            </div>
                        {:else}
                            <input type="hidden" name="team_id" value={teams[0]?.id ?? ''} />
                        {/if}
                        <p class="-mt-1 text-[12px] text-muted-foreground">
                            Needs Ubuntu 24.04 or Debian 12 and SSH access. Coresmith generates a key for this server
                            alone, you add it, then it provisions.
                        </p>
                        <FieldError message={errors.team_id} />
                        <Button type="submit" class="w-full" disabled={processing}>Add server</Button>
                    </div>
                {/snippet}
            </Form>
        {/if}
    </div>

    <div class="rounded-xl border border-border bg-card p-6">
        <h3 class="type-h3 m-0 mb-1">What happens next</h3>
        <p class="mb-4 text-[12.5px] text-muted-foreground">Eight phases, hardened by default, not opt-in.</p>
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
