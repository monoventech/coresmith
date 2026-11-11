<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte'
    import { X } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import { Textarea } from '@/components/ui/textarea'
    import AddressCard from '@/components/settings/AddressCard.svelte'
    import MoveTeamCard from '@/components/settings/MoveTeamCard.svelte'
    import PanelUpdatesCard from '@/components/settings/PanelUpdatesCard.svelte'
    import RemoveCoresmithDialog from '@/components/settings/RemoveCoresmithDialog.svelte'
    import type { Address } from '@/lib/address'
    import type { Moves } from '@/lib/moves'
    import type { PanelUpdates } from '@/lib/panelUpdates.svelte'
    import type { Removal } from '@/lib/removal'
    import { cn } from '@/lib/utils'

    type Key = { id: string; name: string; type: string; fingerprint: string; added: string | null }
    type TeamDeletion = {
        name: string
        servers: number
        provider_servers: number
        providers: string[]
        unconfirmed: { name: string; provider: string }[]
        refusal: string | null
    }

    let {
        instance,
        address = null,
        backups,
        account,
        team_deletion: teamDeletion = null,
        timezones = [],
        keys = [],
        updates,
        moves = null,
        removal = null,
        can
    }: {
        instance: {
            name: string
            version: string
            installed: string | null
            timezone: string
            notification_email: string | null
        }
        address?: Address | null
        backups: { last: { when: string; size: string; verified: boolean } | null; keep: number }
        account: { refusal: string | null; hidden: boolean }
        team_deletion?: TeamDeletion | null
        timezones: string[]
        keys: Key[]
        updates: PanelUpdates
        moves?: Moves | null
        removal?: Removal | null
        can: { manageInstance: boolean; reachServers: boolean }
    } = $props()

    const cloud = $derived(page.props.edition === 'cloud')
    const cardsBeforeDanger = $derived(3 + (moves ? 1 : 0) + (cloud ? 0 : 1) + (address ? 1 : 0))

    let dialog = $state<'key' | 'delete' | 'team' | null>(null)
    let removing = $state(false)
    let backingUp = $state(false)
    let typed = $state('')

    const detachment = $derived.by(() => {
        if (teamDeletion === null || teamDeletion.servers === 0) {
            return "The team has no servers, so nothing is detached. This can't be undone."
        }

        return teamDeletion.servers === 1
            ? "Its server is detached and keeps running as-is. Deploys stop until you add it to another team. This can't be undone."
            : `Its ${teamDeletion.servers} servers are detached and keep running as-is. Deploys stop until you add them to another team. This can't be undone.`
    })

    const providerNote = $derived.by(() => {
        if (teamDeletion === null || teamDeletion.provider_servers === 0) {
            return null
        }

        const where = list(teamDeletion.providers)

        if (teamDeletion.servers === 1) {
            return `It was created at ${where}, so it keeps running and billing there until you destroy it at ${where}, or from Coresmith before you delete the team.`
        }

        return teamDeletion.provider_servers === 1
            ? `One of them was created at ${where}, so it keeps running and billing there until you destroy it at ${where}, or from Coresmith before you delete the team.`
            : `${teamDeletion.provider_servers} of them were created at ${where}, so they keep running and billing there until you destroy them there, or from Coresmith before you delete the team.`
    })

    function list(names: string[]): string {
        return names.length < 2 ? (names[0] ?? 'a provider') : `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`
    }

    function deleteTeam() {
        typed = ''
        dialog = 'team'
    }

    function backUp() {
        backingUp = true
        router.post(route('settings.backups.store'), {}, { preserveScroll: true, onFinish: () => (backingUp = false) })
    }

    function removeKey(key: Key) {
        router.delete(route('ssh-keys.destroy', key.id), { preserveScroll: true })
    }
</script>

<AppHead title="Settings" />

<div class="grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <PanelUpdatesCard {updates} canManage={can.manageInstance} />

    <div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="backups-card">
        <div class="mb-1 flex items-center gap-2.5">
            <h3 class="type-h3 m-0">Backups</h3>
            {#if !cloud && backups.last?.verified}
                <Badge variant="success">Verified</Badge>
            {/if}
        </div>
        {#if cloud}
            <p class="mb-3 text-[12.5px] text-muted-foreground">
                Coresmith Cloud backs itself up every night. Databases on your servers are not included.
            </p>
        {:else}
            <p class="mb-3 text-[12.5px] text-muted-foreground">
                Taken nightly at 04:00 and before every update. Coresmith's database and environment are archived
                together, secrets included.
            </p>
            <div class="font-mono text-[12.5px] leading-[1.9] text-muted-foreground">
                {#if backups.last}
                    <div>
                        last backup, {backups.last.when} · {backups.last.size}{backups.last.verified
                            ? ' · verified'
                            : ' · unreadable'}
                    </div>
                {:else}
                    <div>no backups yet</div>
                {/if}
                <div>retention, the last {backups.keep}</div>
            </div>
            {#if can.manageInstance}
                <div class="mt-auto self-start pt-3">
                    <Button variant="outline" size="sm" disabled={backingUp} onclick={backUp}>Back up now</Button>
                </div>
            {/if}
        {/if}
    </div>

    {#if moves}
        <MoveTeamCard {moves} />
    {/if}

    <div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="ssh-keys-card">
        <div class="mb-1 flex items-center justify-between">
            <h3 class="type-h3 m-0">SSH keys</h3>
            <Button variant="ghost" size="sm" onclick={() => (dialog = 'key')}>Add key</Button>
        </div>
        <p class="mb-2 text-[12.5px] text-muted-foreground" data-testid="ssh-keys-reach">
            {can.reachServers
                ? cloud
                    ? 'Installed on every server you manage. Password login stays off.'
                    : 'Installed on every server you manage, except the machine Coresmith runs on. Password login stays off.'
                : "Deployers don't get shell access, so these aren't installed on any server."}
        </p>
        <div class="flex max-h-72 flex-col overflow-y-auto">
            {#each keys as key (key.id)}
                <div
                    class="flex min-h-11 items-center gap-3 border-t border-border text-[13px]"
                    title={key.fingerprint}
                >
                    <span class="min-w-0 flex-1 truncate font-mono text-[12.5px] font-medium">{key.name}</span>
                    <span class="font-mono text-[11.5px] text-muted-foreground">{key.type}</span>
                    <span class="text-[12px] text-muted-foreground">{key.added}</span>
                    <button
                        type="button"
                        class="focus-ring inline-flex size-8 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted"
                        aria-label="Remove {key.name}"
                        title="Remove {key.name}"
                        onclick={() => removeKey(key)}
                    >
                        <X class="size-4" />
                    </button>
                </div>
            {:else}
                <p class="border-t border-border pt-3 text-[12.5px] text-muted-foreground">No keys yet.</p>
            {/each}
        </div>
    </div>

    {#if !cloud}
        <div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="panel-card">
            <h3 class="type-h3 m-0 mb-1">Installation</h3>
            <p class="mb-3.5 text-[12.5px] text-muted-foreground">
                Name, timezone and alert email for this installation.
            </p>
            {#if can.manageInstance}
                <Form
                    method="put"
                    action={route('settings.instance.update')}
                    options={{ preserveScroll: true }}
                    class="flex flex-1 flex-col"
                >
                    {#snippet children({ errors, processing })}
                        <div class="flex flex-col gap-3.5">
                            <div>
                                <Label for="instance_name" class="mb-1.5">Installation name</Label>
                                <Input
                                    id="instance_name"
                                    name="instance_name"
                                    value={instance.name}
                                    maxlength={60}
                                    required
                                    aria-invalid={errors.instance_name ? true : undefined}
                                />
                                <FieldError message={errors.instance_name} />
                            </div>
                            <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                                <div>
                                    <Label for="timezone" class="mb-1.5">Timezone</Label>
                                    <NativeSelect
                                        id="timezone"
                                        name="timezone"
                                        value={instance.timezone}
                                        options={timezones}
                                    />
                                </div>
                                <div>
                                    <Label for="notification_email" class="mb-1.5">Alert email</Label>
                                    <Input
                                        id="notification_email"
                                        name="notification_email"
                                        type="email"
                                        value={instance.notification_email ?? ''}
                                        placeholder="ops@example.com"
                                        aria-invalid={errors.notification_email ? true : undefined}
                                    />
                                    <FieldError message={errors.notification_email} />
                                </div>
                            </div>
                        </div>
                        <div class="mt-auto self-start pt-3">
                            <Button type="submit" variant="outline" size="sm" disabled={processing}>Save</Button>
                        </div>
                    {/snippet}
                </Form>
            {:else}
                <div class="font-mono text-[12.5px] leading-[1.9] text-muted-foreground">
                    <div>{instance.name}</div>
                    <div>{instance.timezone}</div>
                </div>
            {/if}
        </div>
    {/if}

    {#if address}
        <AddressCard {address} canManage={can.manageInstance} />
    {/if}

    <div
        class={cn(
            'border-danger flex h-full flex-col rounded-xl border bg-card px-5.5 py-5',
            cardsBeforeDanger % 2 === 0 && 'col-span-2 max-[900px]:col-span-1'
        )}
        data-testid="danger-zone"
    >
        <h3 class="type-h3 m-0 mb-1">Danger zone</h3>
        {#if teamDeletion}
            <div class="flex flex-col" data-testid="delete-team">
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Deleting the team detaches every server. The servers, and everything on them, keep running.
                </p>
                {#if teamDeletion.refusal}
                    <p class="mb-3 text-[12.5px] text-foreground">{teamDeletion.refusal}</p>
                {/if}
                <div class="self-start pt-3">
                    <Button
                        variant="outline"
                        size="sm"
                        class="text-destructive-strong"
                        disabled={teamDeletion.refusal !== null}
                        onclick={deleteTeam}>Delete this team</Button
                    >
                </div>
            </div>
        {/if}
        {#if removal}
            <div
                class={cn('flex flex-col', teamDeletion && 'mt-5 border-t border-border pt-4')}
                data-testid="remove-coresmith"
            >
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Removing Coresmith detaches every server. The servers, and everything on them, keep running.
                </p>
                <div class="self-start pt-3">
                    <Button
                        variant="outline"
                        size="sm"
                        class="text-destructive-strong"
                        onclick={() => (removing = true)}>Remove Coresmith</Button
                    >
                </div>
            </div>
        {/if}
        {#if !account.hidden}
            <div
                class={cn('flex flex-1 flex-col', (teamDeletion || removal) && 'mt-5 border-t border-border pt-4')}
                data-testid="close-account"
            >
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    Closing your account signs you out everywhere and removes your SSH keys and API tokens. {account.refusal ??
                        'Your teams keep running without you.'}
                </p>
                <div class="mt-auto self-start pt-3">
                    <Button
                        variant="outline"
                        size="sm"
                        class="text-destructive-strong"
                        disabled={account.refusal !== null}
                        onclick={() => (dialog = 'delete')}>Close your account</Button
                    >
                </div>
            </div>
        {/if}
    </div>
</div>

{#if removal}
    <RemoveCoresmithDialog bind:open={removing} {removal} />
{/if}

<Dialog.Root open={dialog === 'key'} onOpenChange={(open) => !open && (dialog = null)}>
    <Dialog.Content>
        <Form
            method="post"
            action={route('ssh-keys.store')}
            options={{ preserveScroll: true }}
            onSuccess={() => (dialog = null)}
            resetOnSuccess
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Add an SSH key</Dialog.Title>
                    <Dialog.Description
                        >{can.reachServers
                            ? "Public keys only. It's installed on your servers straight away."
                            : "Public keys only. It's installed once you're an owner or admin of a team."}</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4 flex flex-col gap-3">
                    <div>
                        <Label for="key-name" class="mb-1.5">Name</Label>
                        <Input
                            id="key-name"
                            name="name"
                            required
                            placeholder="laptop"
                            aria-invalid={errors.name ? true : undefined}
                        />
                        <FieldError message={errors.name} />
                    </div>
                    <div>
                        <Label for="public_key" class="mb-1.5">Public key</Label>
                        <Textarea
                            id="public_key"
                            name="public_key"
                            rows={3}
                            required
                            placeholder="ssh-ed25519 AAAA…"
                            class="font-mono text-[12.5px] leading-[1.6]"
                            aria-invalid={errors.public_key ? true : undefined}
                        />
                        <FieldError
                            message={errors.public_key
                                ? "That isn't an OpenSSH public key. Paste the line that starts with ssh-ed25519."
                                : null}
                        />
                    </div>
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (dialog = null)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>Add key</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root open={dialog === 'delete'} onOpenChange={(open) => !open && (dialog = null)}>
    <Dialog.Content>
        <Form method="delete" action={route('profile.destroy')} options={{ preserveScroll: true }} resetOnError>
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Close your account?</Dialog.Title>
                    <Dialog.Description>This can't be undone. Type your password to confirm.</Dialog.Description>
                </Dialog.Header>
                <div class="mt-4">
                    <Input
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        aria-label="Password"
                        required
                        aria-invalid={errors.password ? true : undefined}
                    />
                    <FieldError message={errors.password} />
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (dialog = null)}>Keep my account</Button>
                    <Button variant="destructive" type="submit" disabled={processing}>Close it</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

{#if teamDeletion}
    <Dialog.Root open={dialog === 'team'} onOpenChange={(open) => !open && (dialog = null)}>
        <Dialog.Content>
            <Form method="delete" action={route('teams.destroy')} options={{ preserveScroll: true }} resetOnError>
                {#snippet children({ errors, processing })}
                    <Dialog.Header>
                        <Dialog.Title>Delete {teamDeletion.name}?</Dialog.Title>
                        <Dialog.Description>{detachment}</Dialog.Description>
                    </Dialog.Header>
                    {#if providerNote}
                        <p class="mt-3 text-[12.5px] text-muted-foreground">{providerNote}</p>
                    {/if}
                    {#each teamDeletion.unconfirmed as server (server.name)}
                        <p class="mt-3 text-[12.5px] text-muted-foreground">
                            Coresmith could not confirm whether {server.provider} created {server.name}. Check your {server.provider}
                            account and delete it there if it exists.
                        </p>
                    {/each}
                    <div class="mt-4">
                        <Label for="team-name" class="mb-1.5">Type {teamDeletion.name} to confirm</Label>
                        <Input
                            id="team-name"
                            name="name"
                            autocomplete="off"
                            bind:value={typed}
                            aria-invalid={errors.name ? true : undefined}
                        />
                        <FieldError message={errors.name} />
                    </div>
                    <Dialog.Footer>
                        <Button variant="ghost" type="button" onclick={() => (dialog = null)}>Keep the team</Button>
                        <Button variant="destructive" type="submit" disabled={processing || typed !== teamDeletion.name}
                            >Delete it</Button
                        >
                    </Dialog.Footer>
                {/snippet}
            </Form>
        </Dialog.Content>
    </Dialog.Root>
{/if}
