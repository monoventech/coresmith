<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Spinner } from '@/components/ui/spinner'
    import { Form, router } from '@inertiajs/svelte'

    interface Member {
        id: string
        name: string
        email: string
        role: string
        is_you: boolean
    }

    let {
        team,
        members = [],
        roles = [],
        can
    }: {
        team: { id: string; name: string }
        members: Member[]
        roles: { value: string; label: string }[]
        can: { manageMembers: boolean; grantOwner: boolean }
    } = $props()

    const assignable = $derived(can.grantOwner ? roles : roles.filter((role) => role.value !== 'owner'))

    const selectClass =
        'h-9 rounded-md border border-input bg-transparent px-3 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'

    function changeRole(member: Member, role: string) {
        router.put(`/teams/${team.id}/members/${member.id}`, { role }, { preserveScroll: true })
    }

    function remove(member: Member) {
        router.delete(`/teams/${team.id}/members/${member.id}`, { preserveScroll: true })
    }
</script>

<AppHead title="Team" />

<div class="space-y-8">
    <Heading variant="small" title={team.name} description="Who can reach this team's servers, and what they may do." />

    <ul class="divide-y divide-border rounded-xl border border-border">
        {#each members as member (member.id)}
            <li class="flex flex-wrap items-center justify-between gap-4 p-4">
                <div class="min-w-0">
                    <p class="text-sm font-medium">
                        {member.name}
                        {#if member.is_you}
                            <span class="ml-1 text-xs text-muted-foreground">(you)</span>
                        {/if}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">{member.email}</p>
                </div>

                <div class="flex items-center gap-3">
                    {#if can.manageMembers}
                        <label class="sr-only" for="role-{member.id}">Role for {member.name}</label>
                        <select
                            id="role-{member.id}"
                            class={selectClass}
                            value={member.role}
                            onchange={(event) => changeRole(member, (event.currentTarget as HTMLSelectElement).value)}
                        >
                            {#each assignable as role (role.value)}
                                <option value={role.value}>{role.label}</option>
                            {/each}
                        </select>
                        <Button variant="ghost" size="sm" onclick={() => remove(member)}>Remove</Button>
                    {:else}
                        <span class="text-sm text-muted-foreground">{member.role}</span>
                    {/if}
                </div>
            </li>
        {/each}
    </ul>

    {#if can.manageMembers}
        <Form method="post" action="/teams/{team.id}/members" class="max-w-xl space-y-4">
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input id="email" name="email" type="email" required placeholder="colleague@example.com" />
                    <InputError message={errors.email} />
                    <p class="text-xs text-muted-foreground">They need a Coresmith account already.</p>
                </div>

                <div class="grid gap-2">
                    <Label for="role">Role</Label>
                    <select id="role" name="role" class="{selectClass} w-full" required>
                        {#each assignable as role (role.value)}
                            <option value={role.value}>{role.label}</option>
                        {/each}
                    </select>
                    <InputError message={errors.role} />
                </div>

                <Button type="submit" disabled={processing}>
                    {#if processing}<Spinner />{/if}
                    Add member
                </Button>
            {/snippet}
        </Form>
    {/if}
</div>
