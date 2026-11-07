<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Form } from '@inertiajs/svelte'

    let {
        slug,
        path,
        subdomain = null,
        canUpdate
    }: {
        slug: string
        path: string
        subdomain?: string | null
        canUpdate: boolean
    } = $props()

    let editing = $state(false)
    let copied = $state<string | null>(null)

    const addresses = $derived(
        [path, subdomain].filter((value): value is string => typeof value === 'string' && value !== '')
    )
    const prefix = $derived(path.slice(0, path.length - slug.length))

    async function copy(value: string) {
        try {
            await navigator.clipboard.writeText(value)
            copied = value
            setTimeout(() => (copied = null), 2000)
        } catch {
            copied = null
        }
    }
</script>

<div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="team-address-card">
    <div class="mb-1 flex items-center justify-between gap-3">
        <h3 class="type-h3 m-0">Team address</h3>
        {#if canUpdate}
            <Button variant="ghost" size="sm" onclick={() => (editing = true)}>Change</Button>
        {/if}
    </div>
    <p class="mb-3 text-[12.5px] text-muted-foreground">
        Links, bookmarks and tabs open straight into this team, even when you belong to several.
    </p>
    <div class="flex flex-col">
        {#each addresses as address (address)}
            <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
                <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]">{address}</span>
                <button
                    type="button"
                    class="focus-ring quick-action h-8! px-3! text-[12.5px]!"
                    onclick={() => copy(address)}>{copied === address ? 'Copied' : 'Copy'}</button
                >
            </div>
        {/each}
    </div>
</div>

<Dialog.Root bind:open={editing}>
    <Dialog.Content>
        <Form method="put" action={route('settings.team-url.update')} options={{ preserveScroll: true }}>
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Change the team address</Dialog.Title>
                    <Dialog.Description
                        >Old links stop working straight away, so update any bookmarks you shared.</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4">
                    <Label for="team-slug" class="mb-1.5">Address</Label>
                    <div class="flex items-center gap-2">
                        <span class="shrink-0 font-mono text-[12.5px] text-muted-foreground max-sm:hidden"
                            >{prefix}</span
                        >
                        <Input
                            id="team-slug"
                            name="slug"
                            value={slug}
                            required
                            maxlength={40}
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.slug ? true : undefined}
                        />
                    </div>
                    <FieldError message={errors.slug} />
                    <p class="mt-1.5 text-[12px] text-muted-foreground">
                        Lowercase letters, numbers and single hyphens.
                    </p>
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (editing = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>Save</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>
