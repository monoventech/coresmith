<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
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

    type AbilityOption = {
        value: string
        granted: boolean
    }

    let {
        team,
        tokens = [],
        abilities = []
    }: {
        team: { id: string; name: string }
        tokens: Token[]
        abilities: AbilityOption[]
    } = $props()

    const issued = $derived(page.props.flash?.plainTextToken as string | undefined)

    function used(iso: string | null): string {
        return iso ? new Date(iso).toLocaleString() : 'Never used'
    }
</script>

<AppHead title="API tokens" />

<div class="space-y-8">
    <Heading
        variant="small"
        title="API tokens"
        description="Tokens act for {team.name} only, and can never do more than your own role allows. Narrowing a token is possible; widening it is not."
    />

    {#if issued}
        <div class="rounded-xl border border-success/40 bg-success/10 p-4" data-testid="issued-token">
            <p class="text-sm font-medium text-success-strong">Copy this now — it is not shown again.</p>
            <pre class="mt-2 overflow-x-auto rounded-md bg-card p-3 font-mono text-xs break-all whitespace-pre-wrap">{issued}</pre>
        </div>
    {/if}

    <Form method="post" action={route('api-tokens.store')} class="space-y-4 rounded-xl border border-border bg-card p-5">
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="token-name">Name</Label>
                <Input id="token-name" name="name" required placeholder="Deploy from CI" autocomplete="off" />
                <InputError message={errors.name} />
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Abilities</legend>
                {#each abilities as ability (ability.value)}
                    <div class="flex items-center gap-3">
                        <Checkbox
                            id="ability-{ability.value}"
                            name="abilities[]"
                            value={ability.value}
                            disabled={!ability.granted}
                            aria-label={ability.value}
                        />
                        <Label for="ability-{ability.value}" class="font-mono text-xs font-normal">{ability.value}</Label>
                        {#if !ability.granted}
                            <span class="text-xs text-muted-foreground">Your role does not grant this</span>
                        {/if}
                    </div>
                {/each}
                <InputError message={errors.abilities} />
            </fieldset>

            <Button type="submit" size="sm" disabled={processing}>
                {processing ? 'Creating…' : 'Create token'}
            </Button>
        {/snippet}
    </Form>

    {#if tokens.length > 0}
        <ul class="divide-y divide-border rounded-xl border border-border">
            {#each tokens as token (token.id)}
                <li class="flex flex-wrap items-center justify-between gap-4 p-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{token.name}</p>
                        <p class="truncate font-mono text-xs text-muted-foreground">{token.abilities.join(', ')}</p>
                        <p class="text-xs text-muted-foreground">{used(token.last_used_at)}</p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        onclick={() => router.delete(route('api-tokens.destroy', token.id), { preserveScroll: true })}
                    >
                        Revoke
                    </Button>
                </li>
            {/each}
        </ul>
    {:else}
        <p class="rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
            No tokens yet. A token lets a script or CI job do a narrow slice of what you can do.
        </p>
    {/if}
</div>
