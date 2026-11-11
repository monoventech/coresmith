<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { BackupRow, RestoreApp } from '@/lib/databases'

    let {
        backup = $bindable(null),
        serverId,
        databaseId,
        databaseName,
        apps
    }: {
        backup: BackupRow | null
        serverId: string
        databaseId: string
        databaseName: string
        apps: RestoreApp[]
    } = $props()

    let step = $state<'what' | 'sure'>('what')
    let stop = $state<string[]>([])
    let typed = $state('')
    let processing = $state(false)
    let errors = $state<Record<string, string>>({})

    const running = $derived(apps.filter((app) => app.running))

    $effect.pre(() => {
        if (backup) {
            step = 'what'
            typed = ''
            errors = {}
            stop = apps.filter((app) => app.running && app.uses).map((app) => app.id)
        }
    })

    function close() {
        backup = null
    }

    function tick(id: string, on: boolean) {
        stop = on ? [...stop.filter((each) => each !== id), id] : stop.filter((each) => each !== id)
    }

    function restore() {
        if (!backup) {
            return
        }

        processing = true
        router.post(
            route('servers.databases.restore', [serverId, databaseId]),
            { stamp: backup.stamp, name: typed, stop },
            {
                preserveScroll: true,
                onError: (failed) => (errors = failed),
                onSuccess: close,
                onFinish: () => (processing = false)
            }
        )
    }
</script>

<Dialog.Root open={backup !== null} onOpenChange={(open) => !open && close()}>
    <Dialog.Content>
        {#if backup && step === 'what'}
            <Dialog.Header>
                <Dialog.Title>Restore {databaseName} from {backup.when}?</Dialog.Title>
                <Dialog.Description
                    >Everything in {databaseName} is replaced with the backup from {backup.when}. Changes since then are
                    lost.</Dialog.Description
                >
            </Dialog.Header>
            <p class="mt-3 mb-0 text-[13px] text-muted-foreground">
                Coresmith backs up {databaseName} as it is now first, so you can go back.
            </p>
            {#if running.length > 0}
                <fieldset class="m-0 mt-4 border-0 p-0" data-testid="restore-apps">
                    <legend class="mb-1.5 p-0 text-[13px] font-medium">Stop while it restores:</legend>
                    {#each running as app (app.id)}
                        <div class="flex min-h-9 items-center gap-2.5">
                            <Checkbox
                                id="restore-stop-{app.id}"
                                checked={stop.includes(app.id)}
                                onCheckedChange={(on) => tick(app.id, on === true)}
                            />
                            <Label for="restore-stop-{app.id}" class="font-mono text-[13px] font-normal"
                                >{app.name}</Label
                            >
                        </div>
                    {/each}
                    <p class="m-0 mt-1.5 text-[12.5px] text-muted-foreground">
                        Visitors see the maintenance page and workers stop until it's done.
                    </p>
                    <FieldError message={Object.entries(errors).find(([key]) => key.startsWith('stop'))?.[1]} />
                </fieldset>
            {/if}
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={close}>Cancel</Button>
                <Button type="button" onclick={() => (step = 'sure')}>Continue</Button>
            </Dialog.Footer>
        {:else if backup}
            <Dialog.Header>
                <Dialog.Title>Type {databaseName} to restore it.</Dialog.Title>
                <Dialog.Description>There's no undo once it starts.</Dialog.Description>
            </Dialog.Header>
            <div class="mt-4">
                <Label for="restore-confirm" class="sr-only">Type {databaseName} to restore it</Label>
                <Input
                    id="restore-confirm"
                    autocomplete="off"
                    bind:value={typed}
                    aria-invalid={errors.name ? true : undefined}
                />
                <FieldError message={errors.name ?? errors.stamp} />
            </div>
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={close}>Cancel</Button>
                <Button
                    variant="destructive"
                    type="button"
                    disabled={processing || typed !== databaseName}
                    onclick={restore}>Restore {databaseName}</Button
                >
            </Dialog.Footer>
        {/if}
    </Dialog.Content>
</Dialog.Root>
