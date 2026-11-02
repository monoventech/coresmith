<script module lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    export type Invitation = {
        id: string
        email: string
        role: string
        role_label: string
        expires: string
        link: string | null
    }
</script>

<script lang="ts">
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { router } from '@inertiajs/svelte'
    import { Mail } from '@lucide/svelte'

    let {
        teamId,
        invitations,
        canManage
    }: {
        teamId: string
        invitations: Invitation[]
        canManage: boolean
    } = $props()

    let revoking = $state<Invitation | null>(null)
    let revokeOpen = $state(false)
    let copied = $state<string | null>(null)
    let failed = $state<string | null>(null)

    async function copyLink(invitation: Invitation) {
        if (!invitation.link) {
            return
        }

        try {
            await navigator.clipboard.writeText(invitation.link)
            failed = null
            copied = invitation.id
            setTimeout(() => (copied = null), 2000)
        } catch {
            copied = null
            failed = invitation.id
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

        router.delete(route('teams.invitations.destroy', [teamId, revoking.id]), {
            preserveScroll: true,
            onFinish: () => (revokeOpen = false)
        })
    }
</script>

<div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="invitations-card">
    <div class="border-b border-border px-5.5 py-4">
        <h3 class="type-h3 m-0">Invitations</h3>
        <p class="mt-0.5 text-[12.5px] text-muted-foreground">
            Not joined yet. Inviting an address again replaces its link.
        </p>
    </div>
    <ul class="m-0 list-none p-0">
        {#each invitations as invitation (invitation.id)}
            <li class="border-b border-border px-5.5 py-1.5 text-[13.5px] last:border-b-0" data-testid="invitation-row">
                <div class="flex min-h-10 flex-wrap items-center gap-3.5">
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
                        {#if canManage}
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive-strong max-sm:h-11"
                                aria-label="Revoke the invitation to {invitation.email}"
                                onclick={() => askRevoke(invitation)}>Revoke</Button
                            >
                        {/if}
                    </div>
                </div>
                {#if invitation.link}
                    <div class="mt-1 mb-2 flex flex-wrap items-center gap-2 pl-11 max-sm:pl-0">
                        <code
                            class="min-w-0 flex-1 rounded-md bg-muted px-3 py-2 font-mono text-[12px] break-all select-all"
                            data-testid="invitation-link">{invitation.link}</code
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            class="max-sm:h-11"
                            aria-label="{copied === invitation.id ? 'Copied' : 'Copy link'} for {invitation.email}"
                            onclick={() => copyLink(invitation)}
                            >{copied === invitation.id ? 'Copied' : 'Copy link'}</Button
                        >
                    </div>
                    {#if failed === invitation.id}
                        <p class="m-0 mb-2 pl-11 text-[12px] text-destructive-strong max-sm:pl-0" role="status">
                            The link could not be copied here. Select it and copy it yourself.
                        </p>
                    {/if}
                {/if}
            </li>
        {/each}
    </ul>
</div>

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
