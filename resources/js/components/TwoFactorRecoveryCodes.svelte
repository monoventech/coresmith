<script lang="ts">
    import { Button } from '@/components/ui/button'
    import { twoFactorAuthState } from '@/lib/twoFactorAuth.svelte'
    import { Form } from '@inertiajs/svelte'
    import { onMount } from 'svelte'

    const twoFactorAuth = twoFactorAuthState()

    let visible = $state(false)

    async function toggle() {
        if (!visible && twoFactorAuth.state.recoveryCodesList.length === 0) {
            await twoFactorAuth.fetchRecoveryCodes()
        }

        visible = !visible
    }

    onMount(() => {
        if (twoFactorAuth.state.recoveryCodesList.length === 0) {
            void twoFactorAuth.fetchRecoveryCodes()
        }
    })
</script>

<div class="w-full border-t border-border pt-4" data-testid="recovery-codes">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="text-[14px] font-medium">Recovery codes</div>
            <p class="mt-0.5 text-[12.5px] text-muted-foreground">
                Each one signs you in once if you lose your phone. Keep them in a password manager.
            </p>
        </div>
        <div class="flex gap-2">
            {#if visible && twoFactorAuth.state.recoveryCodesList.length > 0}
                <Form
                    method="post"
                    action={route('two-factor.regenerate-recovery-codes')}
                    options={{ preserveScroll: true }}
                    onSuccess={() => twoFactorAuth.fetchRecoveryCodes()}
                >
                    {#snippet children({ processing })}
                        <Button variant="ghost" size="sm" type="submit" disabled={processing}>Replace them</Button>
                    {/snippet}
                </Form>
            {/if}
            <Button variant="outline" size="sm" onclick={toggle}>{visible ? 'Hide codes' : 'Show codes'}</Button>
        </div>
    </div>

    {#if visible}
        {#if twoFactorAuth.state.errors.length > 0}
            <p class="tint-destructive mt-3 rounded-md border px-3.5 py-2.5 text-[13px]" role="alert">
                The recovery codes couldn't be loaded. Reload the page and try again.
            </p>
        {:else}
            <div
                class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 rounded-md bg-muted px-4 py-3 font-mono text-[12.5px] max-sm:grid-cols-1"
            >
                {#each twoFactorAuth.state.recoveryCodesList as recoveryCode (recoveryCode)}
                    <div>{recoveryCode}</div>
                {:else}
                    <div class="text-muted-foreground">Loading…</div>
                {/each}
            </div>
            <p class="mt-2 text-[12px] text-muted-foreground">
                A code stops working once it's used. Replacing them makes every old code useless.
            </p>
        {/if}
    {/if}
</div>
