<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte'
    import { KeyRound } from '@lucide/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import EmptyState from '@/components/EmptyState.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'

    type Token = {
        id: number | string
        name: string
        abilities: string[]
        last_used_at: string | null
        created_at: string | null
        team_id: string | null
    }

    type AbilityOption = { value: string; granted: boolean }

    let {
        team,
        tokens = [],
        abilities = []
    }: {
        team: { id: string; name: string }
        tokens: Token[]
        abilities: AbilityOption[]
    } = $props()

    const LABELS: Record<string, string> = {
        'server.view': 'read servers',
        'application.view': 'read apps',
        'application.deploy': 'deploy',
        'application.update': 'change apps',
        'application.logs': 'read logs',
        'database.view': 'read databases'
    }

    let creating = $state(false)
    let revoking = $state<Token | null>(null)
    let copied = $state(false)

    const issued = $derived(page.flash?.plainTextToken)

    function relative(iso: string | null, fallback: string): string {
        if (!iso) {
            return fallback
        }

        const date = new Date(iso)
        const days = Math.floor((Date.now() - date.getTime()) / 86_400_000)

        if (days < 1) {
            return 'today'
        }

        return days === 1 ? 'yesterday' : date.toLocaleDateString([], { month: 'short', day: 'numeric' })
    }

    async function copyIssued() {
        if (issued) {
            await navigator.clipboard.writeText(issued)
            copied = true
        }
    }

    function revoke() {
        if (!revoking) {
            return
        }

        router.delete(route('api-tokens.destroy', revoking.id), { preserveScroll: true, onFinish: () => (revoking = null) })
    }
</script>

<AppHead title="API tokens" />

{#if issued}
    <div class="tint-success mb-4 max-w-190 rounded-xl border px-4.5 py-3.5" data-testid="issued-token">
        <p class="m-0 text-[13.5px]"><span class="font-semibold">Copy this token now.</span> It is hashed as soon as you leave this page and can't be shown again.</p>
        <div class="mt-2.5 flex flex-wrap items-center gap-2">
            <code class="min-w-0 flex-1 rounded-md bg-muted px-3 py-2 font-mono text-[12px] break-all">{issued}</code>
            <Button variant="outline" size="sm" onclick={copyIssued}>{copied ? 'Copied' : 'Copy'}</Button>
        </div>
    </div>
{/if}

{#if tokens.length === 0}
    <div class="max-w-165">
        <EmptyState
            title="No API tokens yet."
            description="Tokens let the CLI and your CI deploy on your behalf. They're scoped, revocable, and hashed at rest, you see the value exactly once."
        />
        <div class="mt-4"><Button onclick={() => (creating = true)}>Create a token</Button></div>
    </div>
{:else}
    <div class="max-w-190 overflow-hidden rounded-xl border border-border bg-card" data-testid="tokens-card">
        <div class="flex flex-wrap items-center gap-3 border-b border-border px-5.5 py-4">
            <div>
                <h3 class="type-h3 m-0">API tokens</h3>
                <p class="mt-0.5 text-[12.5px] text-muted-foreground">Shown once at creation, hashed after. They act for {team.name} only.</p>
            </div>
            <span class="ml-auto"><Button size="sm" onclick={() => (creating = true)}>Create a token</Button></span>
        </div>
        {#each tokens as token (token.id)}
            <div class="flex min-h-13 flex-wrap items-center gap-3.5 border-b border-border px-5.5 py-1.5 text-[13.5px] last:border-b-0" data-testid="token-row">
                <span class="w-32 truncate font-mono text-[13px] font-medium">{token.name}</span>
                <span class="min-w-0 flex-1 truncate text-[12.5px] text-muted-foreground">{token.abilities.map((ability) => LABELS[ability] ?? ability).join(' · ')}</span>
                <span class="text-[12px] text-muted-foreground">created {relative(token.created_at, 'just now')} · last used {relative(token.last_used_at, 'never')}</span>
                <button
                    type="button"
                    class="focus-ring quick-action text-destructive!"
                    onclick={() => (revoking = token)}
                >
                    Revoke
                </button>
            </div>
        {/each}
    </div>
{/if}

<Dialog.Root bind:open={creating}>
    <Dialog.Content>
        <Form method="post" action={route('api-tokens.store')} options={{ preserveScroll: true }} onSuccess={() => (creating = false)} resetOnSuccess>
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Create a token</Dialog.Title>
                    <Dialog.Description>It acts for {team.name} only and can never do more than your own role allows.</Dialog.Description>
                </Dialog.Header>
                <div class="mt-4 flex flex-col gap-3.5">
                    <div>
                        <Label for="token-name" class="mb-1.5">Name</Label>
                        <Input id="token-name" name="name" required placeholder="ci-deploy" autocomplete="off" aria-invalid={errors.name ? true : undefined} />
                        <FieldError message={errors.name} />
                    </div>
                    <fieldset class="m-0 border-0 p-0">
                        <legend class="type-label mb-1.5">What it can do</legend>
                        <div class="flex flex-col">
                            {#each abilities as ability (ability.value)}
                                <div class="flex min-h-10 items-center gap-2.5">
                                    <Checkbox id="ability-{ability.value}" name="abilities[]" value={ability.value} disabled={!ability.granted} aria-label={ability.value} />
                                    <Label for="ability-{ability.value}" class="font-normal">{LABELS[ability.value] ?? ability.value}</Label>
                                    <span class="font-mono text-[11.5px] text-muted-foreground">{ability.value}</span>
                                    {#if !ability.granted}
                                        <span class="ml-auto text-[12px] text-muted-foreground">your role doesn't grant this</span>
                                    {/if}
                                </div>
                            {/each}
                        </div>
                        <FieldError message={errors.abilities} />
                    </fieldset>
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (creating = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}><KeyRound class="size-4" />Create a token</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root open={revoking !== null} onOpenChange={(open) => !open && (revoking = null)}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Revoke {revoking?.name}?</Dialog.Title>
            <Dialog.Description>Anything using it stops working straight away. This can't be undone, but you can create another.</Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (revoking = null)}>Keep it</Button>
            <Button variant="destructive" onclick={revoke}>Revoke it</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
