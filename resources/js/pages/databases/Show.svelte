<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import BackupsCard from '@/components/databases/BackupsCard.svelte'
    import RestoreDialog from '@/components/databases/RestoreDialog.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { BackupList, BackupRow, RestoreApp, RestoreState } from '@/lib/databases'

    let {
        server,
        database,
        can,
        offsite = null,
        backups = null,
        restore = null,
        apps = []
    }: {
        server: { id: string; name: string; href: string | null }
        database: {
            id: string
            name: string
            type: string
            db_user: string | null
            host: string
            port: number
            status: string
            privilege_level: string
            is_external: boolean
            has_password: boolean
            password_shown: boolean
            shared_redis: boolean
        }
        can: { viewCredentials: boolean; backup: boolean; delete: boolean; restore: boolean }
        offsite?: { sent: string | null; error: string | null } | null
        backups?: BackupList | null
        restore?: RestoreState | null
        apps?: RestoreApp[]
    } = $props()

    const ENGINES: Record<string, string> = { mysql: 'MySQL', postgresql: 'PostgreSQL', redis: 'Redis' }

    let deleting = $state(false)
    let moving = $state(false)
    let rotating = $state(false)
    let working = $state(false)
    let copied = $state<'password' | 'url' | null>(null)
    let typed = $state('')
    let restoring = $state<BackupRow | null>(null)
    let polls = $state(0)

    const LISTING_POLLS = 15
    const takesBackups = $derived(database.type !== 'redis')
    const running = $derived(restore?.state === 'running')

    $effect(() => {
        const listing = takesBackups && !backups && polls < LISTING_POLLS

        if (!listing && !running) {
            return
        }

        const timer = setInterval(
            () => {
                polls += backups ? 0 : 1
                router.reload({ only: ['backups', 'restore'] })
            },
            running ? 3000 : 2000
        )

        return () => clearInterval(timer)
    })

    const password = $derived(database.has_password ? (page.flash?.databasePassword ?? null) : null)
    const redis = $derived(database.type === 'redis')
    const dsn = $derived(
        redis
            ? `redis://${password === null ? '' : `:${encodeURIComponent(password)}@`}${database.host}:${database.port}`
            : `${database.type}://${database.db_user}${password === null ? '' : `:${encodeURIComponent(password)}`}@${database.host}:${database.port}/${database.name}`
    )

    function showPassword() {
        working = true
        router.post(
            route('servers.databases.password.show', [server.id, database.id]),
            {},
            { preserveScroll: true, onFinish: () => (working = false) }
        )
    }

    function rotate() {
        working = true
        router.put(
            route('servers.databases.password.update', [server.id, database.id]),
            {},
            { preserveScroll: true, onFinish: () => ((working = false), (rotating = false), (copied = null)) }
        )
    }

    async function copy(what: 'password' | 'url', value: string) {
        await navigator.clipboard.writeText(value)
        copied = what
    }

    function backup() {
        router.post(route('servers.databases.backup', [server.id, database.id]), {}, { preserveScroll: true })
    }

    function moveToOwnRedis() {
        working = true
        router.post(
            route('servers.databases.own-redis', [server.id, database.id]),
            {},
            { preserveScroll: true, onFinish: () => ((working = false), (moving = false)) }
        )
    }

    function stopDeleting() {
        typed = ''
        deleting = false
    }
</script>

<AppHead title={database.name} />

<BackLink href={server.href ?? route('dashboard')} label={server.href ? server.name : 'Fleet'} />

<div class="mb-6 flex flex-wrap items-end justify-between gap-6">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="type-h1 m-0 font-mono font-medium">{database.name}</h1>
            <Badge variant={database.status === 'active' ? 'success' : 'default'} dot
                >{database.status === 'active' ? 'Ready' : database.status}</Badge
            >
        </div>
        <p class="type-body-sm mt-1.5 text-muted-foreground">
            {ENGINES[database.type] ?? database.type} · on {server.name} · {database.is_external
                ? 'existing server'
                : 'localhost only'}
        </p>
        {#if offsite && !offsite.error}
            <p class="type-body-sm mt-1 text-muted-foreground" data-testid="offsite-status">
                {offsite.sent
                    ? `Last sent to S3 ${offsite.sent}`
                    : 'Not sent to S3 yet. Databases go every night at 02:30.'}
            </p>
        {/if}
    </div>
    {#if can.backup}
        <Button variant="outline" disabled={running} onclick={backup}>Back up now</Button>
    {/if}
</div>

{#if offsite?.error}
    <p class="tint-warning mb-4 rounded-xl border px-4.5 py-3 text-[13.5px]" data-testid="offsite-status">
        {offsite.error}
    </p>
{/if}

<div class="grid grid-cols-2 items-stretch gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card px-5.5 py-5">
        <h3 class="type-h3 m-0 mb-1">Connection</h3>
        <p
            class="mb-3 text-[12.5px] text-muted-foreground"
            data-testid={database.is_external ? 'external-database-note' : undefined}
        >
            {database.is_external
                ? `Coresmith created this database on a ${ENGINES[database.type] ?? database.type} server that was already on ${server.name}. It never reconfigures, restarts or removes that server, so check its network settings yourself.`
                : `Apps on ${server.name} reach it over localhost. It isn't exposed to the internet.`}
        </p>
        <div class="font-mono text-[12.5px] leading-[1.9] text-muted-foreground">
            <div>host {database.host}</div>
            <div>port {database.port}</div>
            {#if database.db_user && !redis}<div>user {database.db_user}</div>{/if}
            {#if password !== null}
                <div class="break-all text-foreground" data-testid="database-password">password {password}</div>
            {/if}
        </div>
        <pre class="terminal mt-3 text-[11.5px] break-all whitespace-pre-wrap" data-testid="database-url">{dsn}</pre>
        {#if database.has_password}
            <p class="mt-2 text-[12px] text-muted-foreground" data-testid="database-password-note">
                {password !== null
                    ? "Copy it now. It isn't shown again; rotate it if you lose it."
                    : database.password_shown
                      ? 'It was shown once and is encrypted at rest. Rotate it if you lose it.'
                      : 'It can be shown once, then only rotating gives you a new one. Both are recorded in the activity log.'}
            </p>
            {#if can.viewCredentials}
                <div class="mt-3 flex flex-wrap gap-2">
                    {#if password === null && !database.password_shown}
                        <Button variant="outline" size="sm" disabled={working} onclick={showPassword}
                            >Show password</Button
                        >
                    {:else if password !== null}
                        <Button variant="outline" size="sm" onclick={() => copy('password', password)}
                            >{copied === 'password' ? 'Copied' : 'Copy password'}</Button
                        >
                        <Button variant="outline" size="sm" onclick={() => copy('url', dsn)}
                            >{copied === 'url' ? 'Copied' : 'Copy URL'}</Button
                        >
                    {/if}
                    <Button variant="ghost" size="sm" disabled={working} onclick={() => (rotating = true)}
                        >Rotate password</Button
                    >
                </div>
            {/if}
        {:else}
            <p class="mt-2 text-[12px] text-muted-foreground" data-testid="shared-redis-note">
                It has no password, so every app on {server.name} can read and change what's in it.
            </p>
            {#if can.viewCredentials}
                <div class="mt-3">
                    <Button variant="outline" size="sm" disabled={working} onclick={() => (moving = true)}
                        >Give it its own Redis</Button
                    >
                </div>
            {/if}
        {/if}
    </div>

    <div class="flex flex-col gap-4">
        <div class="rounded-xl border border-border bg-card px-5.5 py-5">
            <h3 class="type-h3 m-0 mb-1">Access</h3>
            <p class="mb-3 text-[12.5px] text-muted-foreground">
                {database.shared_redis
                    ? `No user of its own. Every app on ${server.name} shares it.`
                    : redis
                      ? 'An instance of its own. Only apps with its password get in.'
                      : 'Its own user, with only the privileges its app needs.'}
            </p>
            <div class="font-mono text-[12.5px] leading-[1.9] text-muted-foreground">
                {#if !redis}<div>privileges {database.privilege_level}</div>{/if}
                <div>{database.is_external ? 'reachable as its server allows' : 'reachable from localhost'}</div>
            </div>
        </div>
        {#if takesBackups}
            <BackupsCard
                serverName={server.name}
                databaseName={database.name}
                {backups}
                {restore}
                s3={offsite !== null}
                canRestore={can.restore}
                canBackup={can.backup}
                gaveUp={polls >= LISTING_POLLS}
                onRestore={(row) => (restoring = row)}
                onBackup={backup}
            />
        {/if}
        {#if can.delete}
            <div class="border-danger flex-1 rounded-xl border bg-card px-5.5 py-5">
                <h3 class="type-h3 m-0 mb-1">Danger zone</h3>
                <p class="mb-3 text-[12.5px] text-muted-foreground">
                    {database.shared_redis
                        ? "Removes it from Coresmith. The shared Redis keeps running and keeps what's in it."
                        : redis
                          ? `Stops and removes its Redis instance on ${server.name}, with everything in it.`
                          : `Drops it and its user from ${server.name}. Take a backup first if anything still needs the data.`}
                </p>
                <Button variant="outline" size="sm" class="text-destructive-strong" onclick={() => (deleting = true)}
                    >Delete this database</Button
                >
            </div>
        {/if}
    </div>
</div>

<Dialog.Root bind:open={moving}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Give {database.name} a Redis of its own?</Dialog.Title>
            <Dialog.Description
                >Coresmith starts a Redis on a free port with its own password and shows the password once. What's in
                the shared Redis stays there: queued jobs, sessions and cached values don't move. Apps keep using the
                shared Redis until you give them the new port and password and deploy.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (moving = false)}>Keep it shared</Button>
            <Button disabled={working} onclick={moveToOwnRedis}>Give it its own Redis</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={rotating}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Rotate the password of {database.name}?</Dialog.Title>
            <Dialog.Description
                >Apps using the current password lose access until you give them the new one and deploy.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (rotating = false)}>Keep it</Button>
            <Button disabled={working} onclick={rotate}>Rotate it</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={deleting} onOpenChange={(open) => !open && (typed = '')}>
    <Dialog.Content>
        <Form
            method="delete"
            action={route('servers.databases.destroy', [server.id, database.id])}
            options={{ preserveScroll: true }}
            onSuccess={stopDeleting}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Delete {database.name}?</Dialog.Title>
                    <Dialog.Description
                        >{database.shared_redis
                            ? `It's removed from Coresmith. The shared Redis on ${server.name} keeps running and keeps what's in it.`
                            : redis
                              ? `Coresmith stops and removes its Redis instance on ${server.name}. Everything in it is gone and apps using it stop working.`
                              : `Coresmith drops it and its user from ${server.name}. Everything in it is gone and apps using it stop working. Take a backup first if anything still needs the data.`}</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4">
                    <Label for="delete-confirm" class="mb-1.5">Type {database.name} to confirm</Label>
                    <Input
                        id="delete-confirm"
                        name="confirm"
                        autocomplete="off"
                        bind:value={typed}
                        aria-invalid={errors.confirm ? true : undefined}
                    />
                    <FieldError message={errors.confirm} />
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={stopDeleting}>Keep it</Button>
                    <Button variant="destructive" type="submit" disabled={processing || typed !== database.name}
                        >Delete it</Button
                    >
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

{#if can.restore}
    <RestoreDialog
        bind:backup={restoring}
        serverId={server.id}
        databaseId={database.id}
        databaseName={database.name}
        {apps}
    />
{/if}
