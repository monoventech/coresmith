<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { MoveAway } from '@/lib/moves'

    let { away }: { away: MoveAway } = $props()

    let code = $state('')
    let working = $state(false)
    let error = $state<string | undefined>(undefined)

    const moving = $derived(away.moving)

    function start(event: SubmitEvent) {
        event.preventDefault()
        error = undefined
        router.post(
            route('settings.moving.store'),
            { code: code.trim() },
            {
                preserveScroll: true,
                onStart: () => (working = true),
                onFinish: () => (working = false),
                onSuccess: () => {
                    code = ''
                    window.location.assign(route('settings.moving.file'))
                },
                onError: (errors: Record<string, string>) => (error = errors.code)
            }
        )
    }

    function cancel() {
        router.delete(route('settings.moving.destroy'), {
            preserveScroll: true,
            onStart: () => (working = true),
            onFinish: () => (working = false)
        })
    }
</script>

<div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="move-away-card">
    {#if moving}
        <h3 class="type-h3 m-0 mb-1">Moving to {moving.to}</h3>
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Since {moving.since}, Coresmith has stopped managing this team's servers, and they keep running. Upload the
            move file {moving.where} to finish, or cancel the move to keep the team here.
        </p>
        <div class="mt-auto flex flex-wrap gap-2.5 pt-3">
            {#if moving.file}
                <Button size="sm" href={moving.file} download>Download move file</Button>
            {/if}
            {#if moving.can_cancel}
                <Button variant="ghost" size="sm" disabled={working} onclick={cancel}>Cancel the move</Button>
            {/if}
        </div>
    {:else}
        <h3 class="type-h3 m-0 mb-1">Move this team to Coresmith Cloud</h3>
        <p class="mb-3 text-[12.5px] text-muted-foreground">
            Coresmith Cloud runs Coresmith for you. Bring this team's servers, apps and databases there without setting
            the servers up again. Their apps keep serving the whole time.
        </p>
        <form class="mt-auto border-t border-border pt-3" onsubmit={start}>
            <Label for="move-away-code" class="mb-1.5">Move code</Label>
            <Input
                id="move-away-code"
                bind:value={code}
                required
                autocomplete="off"
                spellcheck="false"
                class="font-mono"
                aria-describedby="move-away-hint"
                aria-invalid={error ? true : undefined}
            />
            <p id="move-away-hint" class="mt-1.5 mb-0 text-[12px] text-muted-foreground">
                Create one in Coresmith Cloud, under Settings, General.
            </p>
            <FieldError message={error} />
            <div class="pt-3">
                <Button type="submit" variant="outline" size="sm" disabled={working}>Download move file</Button>
            </div>
        </form>
    {/if}
</div>
