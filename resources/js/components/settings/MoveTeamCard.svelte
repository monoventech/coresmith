<script lang="ts">
    import { Form, router } from '@inertiajs/svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { MoveCode, Moves } from '@/lib/moves'

    let { moves }: { moves: Moves } = $props()

    let working = $state(false)
    let copied = $state(false)
    let error = $state<string | undefined>(undefined)

    const code = $derived(moves.code)
    const preview = $derived(code?.preview ?? null)

    function counted(count: number, one: string, many: string): string {
        return `${count} ${count === 1 ? one : many}`
    }

    function listed(parts: string[]): string {
        return parts.length < 2 ? (parts[0] ?? '') : `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`
    }

    const summary = $derived(
        preview
            ? `${preview.team} moves here from ${preview.from} with ${listed([
                  counted(preview.servers.length, 'server', 'servers'),
                  counted(preview.applications, 'app', 'apps'),
                  counted(preview.databases, 'database', 'databases')
              ])}.`
            : ''
    )
    const reconnect = $derived(
        preview
            ? listed([
                  ...(preview.github > 0 ? [`GitHub for ${counted(preview.github, 'app', 'apps')}`] : []),
                  ...Object.entries(preview.reconnect).map(
                      ([provider, count]) => `${provider} for ${counted(count, 'app', 'apps')}`
                  ),
                  ...(preview.webhooks > 0
                      ? [counted(preview.webhooks, 'deploy webhook address', 'deploy webhook addresses')]
                      : [])
              ])
            : ''
    )

    function create() {
        router.post(
            route('settings.moves.store'),
            {},
            { preserveScroll: true, onStart: () => (working = true), onFinish: () => (working = false) }
        )
    }

    function remove(current: MoveCode) {
        router.delete(route('settings.moves.destroy', current.id), { preserveScroll: true })
    }

    function move(current: MoveCode) {
        error = undefined
        router.post(
            route('settings.moves.import', current.id),
            {},
            {
                onStart: () => (working = true),
                onError: (errors: Record<string, string>) => (error = errors.file),
                onFinish: () => (working = false)
            }
        )
    }

    async function copy(text: string) {
        try {
            await navigator.clipboard.writeText(text)
            copied = true
            setTimeout(() => (copied = false), 2000)
        } catch {
            copied = false
        }
    }
</script>

<div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="move-team-card">
    <h3 class="type-h3 m-0 mb-1">Move a team from Coresmith Cloud</h3>
    {#if !code}
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Bring a team's servers, apps and databases here without setting the servers up again. Their apps keep
            serving the whole time.
        </p>
        <div class="mt-auto self-start pt-3">
            <Button variant="outline" size="sm" disabled={working} onclick={create}>Create a move code</Button>
        </div>
    {:else if !preview}
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Paste this code in Coresmith Cloud, Billing, Move to self-host, then upload the move file it gives you. It
            works until {code.until}.
        </p>
        <div class="flex min-h-10 items-center gap-2.5 border-t border-border">
            <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]" data-testid="move-code">{code.text}</span>
            <button
                type="button"
                class="focus-ring quick-action h-8! px-3! text-[12.5px]!"
                onclick={() => copy(code.text)}>{copied ? 'Copied' : 'Copy'}</button
            >
        </div>
        <Form
            method="post"
            action={route('settings.moves.upload', code.id)}
            options={{ preserveScroll: true }}
            class="mt-3 border-t border-border pt-3"
        >
            {#snippet children({ errors, processing })}
                <Label for="move-file" class="mb-1.5">Move file</Label>
                <Input
                    id="move-file"
                    name="file"
                    type="file"
                    accept=".coresmith-move,application/json"
                    required
                    aria-invalid={errors.file ? true : undefined}
                />
                <FieldError message={errors.file} />
                <div class="mt-3 flex flex-wrap gap-2.5">
                    <Button type="submit" variant="outline" size="sm" disabled={processing}>Upload</Button>
                    <Button type="button" variant="ghost" size="sm" onclick={() => remove(code)}>Remove the code</Button
                    >
                </div>
            {/snippet}
        </Form>
    {:else}
        <p class="mb-1 text-[13px]" data-testid="move-summary">{summary}</p>
        <p class="mb-1 font-mono text-[12.5px] text-muted-foreground">{preview.servers.join(' · ')}</p>
        {#if reconnect}
            <p class="mb-1 text-[12.5px] text-muted-foreground">To reconnect afterwards: {reconnect}.</p>
        {/if}
        {#if preview.members > 0}
            <p class="mb-1 text-[12.5px] text-muted-foreground">
                {counted(preview.members, 'teammate gets', 'teammates get')} an invitation.
            </p>
        {/if}
        <FieldError message={error} />
        <div class="mt-auto flex flex-wrap gap-2.5 pt-3">
            <Button size="sm" disabled={working} onclick={() => move(code)}>Move team</Button>
            <Button variant="ghost" size="sm" disabled={working} onclick={() => remove(code)}>Remove</Button>
        </div>
    {/if}
</div>
