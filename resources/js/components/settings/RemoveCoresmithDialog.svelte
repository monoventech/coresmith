<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { Removal } from '@/lib/removal'

    let { open = $bindable(false), removal }: { open: boolean; removal: Removal } = $props()

    const ENGINES: Record<string, string> = { pgsql: 'PostgreSQL', mysql: 'MySQL', mariadb: 'MariaDB' }

    let step = $state<'what' | 'sure'>('what')
    let address = $state('')
    let password = $state('')
    let code = $state('')
    let processing = $state(false)
    let errors = $state<Record<string, string>>({})
    let keep = $state<HTMLElement | null>(null)

    const servers = $derived(
        removal.servers === 1
            ? 'Your server, and everything on it,'
            : `Your ${removal.servers} servers, and everything on them,`
    )

    $effect.pre(() => {
        if (open) {
            step = 'what'
            address = ''
            password = ''
            code = ''
            errors = {}
        }
    })

    function list(names: string[]): string {
        return names.length < 2 ? (names[0] ?? '') : `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`
    }

    function remove() {
        processing = true
        router.post(
            route('settings.removal.store'),
            { address, password, ...(removal.two_factor ? { code } : {}) },
            {
                preserveScroll: true,
                onError: (failed) => (errors = failed),
                onFinish: () => (processing = false)
            }
        )
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content
        onOpenAutoFocus={(event) => {
            event.preventDefault()
            keep?.focus()
        }}
    >
        {#if step === 'what'}
            <Dialog.Header>
                <Dialog.Title>Remove Coresmith?</Dialog.Title>
                <Dialog.Description>This is permanent and can't be undone.</Dialog.Description>
            </Dialog.Header>
            <ul class="mt-4 mb-0 flex flex-col gap-2 pl-4.5 text-[13px] leading-normal" data-testid="removal-what">
                {#if removal.servers > 0}
                    <li>{servers} keep running. Deploys stop until you point another Coresmith at them.</li>
                    <li>Coresmith takes its key off each server it can reach.</li>
                {/if}
                {#if removal.this_machine_apps}
                    <li>Apps on this machine keep running.</li>
                {/if}
                {#if removal.other_teams.length > 0}
                    <li>This also removes Coresmith for {list(removal.other_teams)}.</li>
                {/if}
                <li>
                    {removal.database === 'sqlite' ? 'Its settings, backups and database' : 'Its settings and backups'} are
                    saved in /root on this server first. Everything else of Coresmith's is deleted.
                </li>
                {#if removal.database !== 'sqlite'}
                    <li>
                        Its {ENGINES[removal.database] ?? removal.database} database {removal.database_name} stays on its
                        database server.
                    </li>
                {/if}
            </ul>
            {#if !removal.helper}
                <div class="tint-warning mt-4 rounded-lg border px-3.5 py-3 text-[12.5px]" data-testid="removal-helper">
                    <p class="m-0">
                        Coresmith's removal helper isn't installed. Run the installer once on this server, then come
                        back:
                    </p>
                    <pre
                        class="terminal mt-2 mb-0 text-[11.5px] break-all whitespace-pre-wrap">{removal.installer}</pre>
                </div>
            {/if}
            <Dialog.Footer>
                <Button variant="ghost" type="button" bind:ref={keep} onclick={() => (open = false)}
                    >Keep Coresmith</Button
                >
                <Button type="button" disabled={!removal.helper} onclick={() => (step = 'sure')}>Continue</Button>
            </Dialog.Footer>
        {:else}
            <form
                onsubmit={(event) => {
                    event.preventDefault()
                    remove()
                }}
            >
                <Dialog.Header>
                    <Dialog.Title>Are you sure?</Dialog.Title>
                    <Dialog.Description
                        >{removal.two_factor
                            ? `There's no undo. Type ${removal.address}, your password and a code from your authenticator app to remove Coresmith.`
                            : `There's no undo. Type ${removal.address} and your password to remove Coresmith.`}</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4 flex flex-col gap-3">
                    <div>
                        <Label for="removal-address" class="mb-1.5">Coresmith's address</Label>
                        <Input
                            id="removal-address"
                            autocomplete="off"
                            spellcheck={false}
                            bind:value={address}
                            aria-invalid={errors.address ? true : undefined}
                        />
                        <FieldError message={errors.address} />
                    </div>
                    <div>
                        <Label for="removal-password" class="mb-1.5">Your password</Label>
                        <Input
                            id="removal-password"
                            type="password"
                            autocomplete="current-password"
                            bind:value={password}
                            aria-invalid={errors.password ? true : undefined}
                        />
                        <FieldError message={errors.password} />
                    </div>
                    {#if removal.two_factor}
                        <div>
                            <Label for="removal-code" class="mb-1.5">Code from your authenticator app</Label>
                            <Input
                                id="removal-code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                bind:value={code}
                                aria-invalid={errors.code ? true : undefined}
                            />
                            <FieldError message={errors.code} />
                        </div>
                    {/if}
                    {#if errors.removal}
                        <p
                            class="tint-destructive m-0 rounded-lg border px-3.5 py-2.5 text-[12.5px]"
                            role="alert"
                            data-testid="removal-refusal"
                        >
                            {errors.removal}
                        </p>
                    {/if}
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (open = false)}>Keep Coresmith</Button>
                    <Button variant="destructive" type="submit" disabled={processing || address !== removal.address}
                        >Remove Coresmith</Button
                    >
                </Dialog.Footer>
            </form>
        {/if}
    </Dialog.Content>
</Dialog.Root>
