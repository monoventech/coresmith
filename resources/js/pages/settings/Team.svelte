<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import CloudProvidersCard from '@/components/settings/CloudProvidersCard.svelte'
    import CustomDomainCard from '@/components/settings/CustomDomainCard.svelte'
    import type { SingleSignOn } from '@/components/settings/SingleSignOnCard.svelte'
    import SingleSignOnCard from '@/components/settings/SingleSignOnCard.svelte'
    import TeamAddressCard from '@/components/settings/TeamAddressCard.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { CloudAccounts } from '@/lib/cloud'
    import { getInitials } from '@/lib/initials'
    import { Form, router } from '@inertiajs/svelte'
    import { X } from '@lucide/svelte'

    interface Member {
        id: string
        name: string
        email: string
        role: string
        role_label: string | null
        two_factor: boolean
        is_you: boolean
    }

    type Address = {
        path: string
        subdomain: string | null
        domain: {
            hostname: string
            url: string
            verified: boolean
            checked: string | null
            failure: string | null
            records: { type: string; name: string; value: string }[]
        } | null
    }

    let {
        team,
        address,
        members = [],
        roles = [],
        can,
        sso,
        cloud
    }: {
        team: { id: string; name: string; slug: string }
        address: Address
        members: Member[]
        roles: { value: string; label: string }[]
        can: { update: boolean; manageMembers: boolean; grantOwner: boolean }
        sso: SingleSignOn
        cloud: CloudAccounts
    } = $props()

    let inviting = $state(false)
    let removing = $state<Member | null>(null)
    let removeOpen = $state(false)

    const assignable = $derived(can.grantOwner ? roles : roles.filter((role) => role.value !== 'owner'))

    function changeRole(member: Member, role: string) {
        router.put(route('teams.members.update', [team.id, member.id]), { role }, { preserveScroll: true })
    }

    function ask(member: Member) {
        removing = member
        removeOpen = true
    }

    function remove() {
        if (!removing) {
            return
        }

        router.delete(route('teams.members.destroy', [team.id, removing.id]), {
            preserveScroll: true,
            onFinish: () => (removeOpen = false)
        })
    }
</script>

<AppHead title="Team" />

<div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="team-card">
    <div class="flex flex-wrap items-center gap-3 border-b border-border px-5.5 py-4">
        <div>
            <h3 class="type-h3 m-0">{team.name}</h3>
            <p class="mt-0.5 text-[12.5px] text-muted-foreground">Everyone with access to this panel.</p>
        </div>
        {#if can.manageMembers}
            <span class="ml-auto"><Button size="sm" onclick={() => (inviting = true)}>Invite someone</Button></span>
        {/if}
    </div>
    <ul class="m-0 list-none p-0">
        {#each members as member (member.id)}
            {@const manageable = can.manageMembers && !member.is_you && (can.grantOwner || member.role !== 'owner')}
            <li
                class="flex min-h-13 flex-wrap items-center gap-3.5 border-b border-border px-5.5 py-1.5 text-[13.5px]"
                data-testid="member-row"
            >
                <span
                    class="inline-flex size-7.5 shrink-0 items-center justify-center rounded-full bg-muted text-[12px] font-semibold text-muted-foreground"
                >
                    {getInitials(member.name)}
                </span>
                <span class="w-36 truncate font-medium max-sm:w-auto max-sm:flex-1">
                    {member.name}<span class="text-[12px] font-normal text-muted-foreground"
                        >{member.is_you ? ' (you)' : ''}</span
                    >
                </span>
                <span
                    class="min-w-0 flex-1 truncate font-mono text-[12px] text-muted-foreground max-sm:basis-full max-sm:pl-11"
                    >{member.email}</span
                >
                {#if manageable}
                    <div class="w-32">
                        <NativeSelect
                            class="h-8 text-[12.5px]"
                            aria-label="Role for {member.name}"
                            value={member.role}
                            options={assignable}
                            onchange={(event) => changeRole(member, (event.currentTarget as HTMLSelectElement).value)}
                        />
                    </div>
                {:else}
                    <span class="w-17.5 text-[12.5px] text-muted-foreground">{member.role_label}</span>
                {/if}
                <Badge variant={member.two_factor ? 'success' : 'outline'}>{member.two_factor ? '2FA' : 'No 2FA'}</Badge
                >
                {#if manageable}
                    <button
                        type="button"
                        class="focus-ring inline-flex size-8 cursor-pointer items-center justify-center rounded-md bg-transparent text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted"
                        aria-label="Remove {member.name}"
                        title="Remove {member.name}"
                        onclick={() => ask(member)}
                    >
                        <X class="size-4" />
                    </button>
                {/if}
            </li>
        {/each}
    </ul>
    <p class="m-0 px-5.5 py-3 text-[12.5px] text-muted-foreground">
        Admins can provision and delete servers. Deployers can deploy and roll back.
    </p>
</div>

<div class="mt-4 grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <TeamAddressCard slug={team.slug} path={address.path} subdomain={address.subdomain} canUpdate={can.update} />
    <CustomDomainCard domain={address.domain} canUpdate={can.update} />
</div>

<div class="mt-4">
    <SingleSignOnCard {sso} />
</div>

<div class="mt-4">
    <CloudProvidersCard {cloud} />
</div>

<Dialog.Root bind:open={inviting}>
    <Dialog.Content>
        <Form
            method="post"
            action={route('teams.members.store', team.id)}
            options={{ preserveScroll: true }}
            onSuccess={() => (inviting = false)}
            resetOnSuccess
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Invite someone</Dialog.Title>
                    <Dialog.Description
                        >They need a Coresmith account on this panel already. Their role decides what they can change.</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4 flex flex-col gap-3">
                    <div>
                        <Label for="invite-email" class="mb-1.5">Email address</Label>
                        <Input
                            id="invite-email"
                            name="email"
                            type="email"
                            required
                            placeholder="colleague@example.com"
                            aria-invalid={errors.email ? true : undefined}
                        />
                        <FieldError message={errors.email} />
                    </div>
                    <div>
                        <Label for="invite-role" class="mb-1.5">Role</Label>
                        <NativeSelect id="invite-role" name="role" options={assignable} value="member" />
                        <FieldError message={errors.role} />
                    </div>
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (inviting = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>Add to the team</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={removeOpen}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Remove {removing?.name}?</Dialog.Title>
            <Dialog.Description
                >They lose access to {team.name} straight away. Their account and anything they deployed stay as they are.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (removeOpen = false)}>Keep them</Button>
            <Button variant="destructive" onclick={remove}>Remove from the team</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
