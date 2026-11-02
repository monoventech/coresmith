<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import AuditLogCard from '@/components/settings/AuditLogCard.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import CloudProvidersCard from '@/components/settings/CloudProvidersCard.svelte'
    import CustomDomainCard from '@/components/settings/CustomDomainCard.svelte'
    import type { OffsiteDestination } from '@/components/settings/OffsiteBackupsCard.svelte'
    import OffsiteBackupsCard from '@/components/settings/OffsiteBackupsCard.svelte'
    import PlanNote from '@/components/settings/PlanNote.svelte'
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
    import { Form, page, router } from '@inertiajs/svelte'
    import { Mail, X } from '@lucide/svelte'

    interface Member {
        id: string
        name: string
        email: string
        role: string
        role_label: string | null
        two_factor: boolean
        is_you: boolean
    }

    interface Invitation {
        id: string
        email: string
        role: string
        role_label: string
        expires: string
        link: string | null
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
        invitations = [],
        invite_links: inviteLinks = false,
        roles = [],
        can,
        sso,
        cloud,
        offsite = null
    }: {
        team: { id: string; name: string; slug: string }
        address: Address
        members: Member[]
        invitations?: Invitation[]
        invite_links?: boolean
        roles: { value: string; label: string }[]
        can: { update: boolean; manageMembers: boolean; grantOwner: boolean }
        sso: SingleSignOn
        cloud: CloudAccounts
        offsite?: OffsiteDestination | null
    } = $props()

    const billing = $derived(page.props.billing)
    const memberNote = $derived(billing?.notes.members ?? null)
    const inviteHelp = $derived(
        page.props.edition === 'cloud'
            ? 'They get an email with a link to join.'
            : inviteLinks
              ? 'Someone with a confirmed account here joins straight away. For anyone else you get a link to send them.'
              : 'Someone with a confirmed account here joins straight away. Anyone else gets an email with a link to join.'
    )

    let inviting = $state(false)
    let removing = $state<Member | null>(null)
    let removeOpen = $state(false)
    let revoking = $state<Invitation | null>(null)
    let revokeOpen = $state(false)
    let copied = $state<string | null>(null)

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

    async function copyLink(invitation: Invitation) {
        if (!invitation.link) {
            return
        }

        try {
            await navigator.clipboard.writeText(invitation.link)
            copied = invitation.id
            setTimeout(() => (copied = null), 2000)
        } catch {
            copied = null
        }
    }

    function askRevoke(invitation: Invitation) {
        revoking = invitation
        revokeOpen = true
    }

    function revoke() {
        if (!revoking) {
            return
        }

        router.delete(route('teams.invitations.destroy', [team.id, revoking.id]), {
            preserveScroll: true,
            onFinish: () => (revokeOpen = false)
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
            <span class="ml-auto"
                ><Button size="sm" disabled={!!memberNote} onclick={() => (inviting = true)}>Invite someone</Button
                ></span
            >
        {/if}
        {#if memberNote}
            <div class="basis-full"><PlanNote note={memberNote} /></div>
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
    {#if invitations.length > 0}
        <p class="type-label m-0 border-b border-border px-5.5 pt-4 pb-2 text-muted-foreground" id="invitations-label">
            Invited
        </p>
        <ul class="m-0 list-none p-0" aria-labelledby="invitations-label">
            {#each invitations as invitation (invitation.id)}
                <li
                    class="flex min-h-13 flex-wrap items-center gap-3.5 border-b border-border px-5.5 py-1.5 text-[13.5px]"
                    data-testid="invitation-row"
                >
                    <span
                        class="inline-flex size-7.5 shrink-0 items-center justify-center rounded-full border border-dashed border-border text-muted-foreground"
                        aria-hidden="true"
                    >
                        <Mail class="size-3.5" />
                    </span>
                    <span class="min-w-0 flex-1 truncate font-mono text-[12px] text-muted-foreground"
                        >{invitation.email}</span
                    >
                    <div class="flex flex-wrap items-center gap-3.5 max-sm:basis-full max-sm:pl-11">
                        <span class="text-[12.5px] text-muted-foreground">{invitation.role_label}</span>
                        <span class="text-[12px] text-muted-foreground">expires {invitation.expires}</span>
                        {#if invitation.link}
                            <Button
                                variant="outline"
                                size="sm"
                                class="max-sm:h-11"
                                aria-label="{copied === invitation.id ? 'Copied' : 'Copy link'} for {invitation.email}"
                                onclick={() => copyLink(invitation)}
                                >{copied === invitation.id ? 'Copied' : 'Copy link'}</Button
                            >
                        {/if}
                        {#if can.manageMembers}
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive-strong max-sm:h-11"
                                aria-label="Revoke the invitation to {invitation.email}"
                                onclick={() => askRevoke(invitation)}>Revoke</Button
                            >
                        {/if}
                    </div>
                </li>
            {/each}
        </ul>
    {/if}
    <p class="m-0 px-5.5 py-3 text-[12.5px] text-muted-foreground">
        Admins can provision and delete servers. Deployers can deploy and roll back.
    </p>
</div>

<div class="mt-4 grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <TeamAddressCard slug={team.slug} path={address.path} subdomain={address.subdomain} canUpdate={can.update} />
    <CustomDomainCard domain={address.domain} canUpdate={can.update} planNote={billing?.notes.custom_domain} />
</div>

<div class="mt-4">
    <SingleSignOnCard {sso} planNote={billing?.notes.single_sign_on} />
</div>

<div class="mt-4">
    <CloudProvidersCard {cloud} />
</div>

{#if can.update}
    <div class="mt-4 grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
        <AuditLogCard planNote={billing?.notes.audit_log_export} />
        <OffsiteBackupsCard {offsite} planNote={billing?.notes.offsite_backups} />
    </div>
{/if}

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
                    <Dialog.Description>{inviteHelp} Their role decides what they can change.</Dialog.Description>
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

<Dialog.Root bind:open={revokeOpen}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Revoke the invitation to {revoking?.email}?</Dialog.Title>
            <Dialog.Description
                >The link stops working straight away. You can invite them again later.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (revokeOpen = false)}>Keep it</Button>
            <Button variant="destructive" onclick={revoke}>Revoke invitation</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
