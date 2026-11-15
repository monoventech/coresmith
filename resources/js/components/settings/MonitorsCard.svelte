<script lang="ts" module>
    export type MonitorOption = { value: number; label: string }

    export type Monitor = {
        kind: string
        name: string
        rule: string
        enabled: boolean
        threshold: number
        duration: number
        levels: MonitorOption[]
        durations: MonitorOption[]
    }

    export type MonitorOverride = {
        kind: string
        server: { id: string; name: string }
        rule: string
        threshold: number
        duration: number
    }

    export type Monitors = {
        canChange: boolean
        plan: boolean
        monitors: Monitor[]
        overrides: MonitorOverride[]
        servers: { id: string; name: string }[]
        serverKinds: string[]
        footnote: string
    }
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import { Switch } from '@/components/ui/switch'

    let { monitors }: { monitors: Monitors } = $props()

    let busy = $state<Record<string, boolean>>({})
    let editing = $state<Monitor | null>(null)
    let teamOpen = $state(false)
    let serverOpen = $state(false)
    let overriding = $state<MonitorOverride | null>(null)
    let serverId = $state('')
    let kind = $state('')
    let level = $state('')
    let duration = $state('')
    let saving = $state(false)

    const changeable = $derived(monitors.plan && monitors.canChange)
    const serverKinds = $derived(monitors.monitors.filter((monitor) => monitors.serverKinds.includes(monitor.kind)))
    const chosen = $derived(monitors.monitors.find((monitor) => monitor.kind === kind) ?? null)

    function options(list: MonitorOption[]) {
        return list.map((option) => ({ value: String(option.value), label: option.label }))
    }

    function labels(monitor: Monitor | null) {
        return monitor?.kind === 'apps'
            ? { level: 'Alert on', duration: 'After' }
            : { level: 'Alert above', duration: 'For' }
    }

    function flip(monitor: Monitor, on: boolean) {
        busy = { ...busy, [monitor.kind]: true }
        router.put(
            route('settings.monitors.update', { kind: monitor.kind }),
            { enabled: on },
            { preserveScroll: true, onFinish: () => (busy = { ...busy, [monitor.kind]: false }) }
        )
    }

    function edit(monitor: Monitor) {
        editing = monitor
        level = String(monitor.threshold)
        duration = String(monitor.duration)
        teamOpen = true
    }

    function saveTeam(event: SubmitEvent) {
        event.preventDefault()

        if (editing === null) {
            return
        }

        saving = true
        router.put(
            route('settings.monitors.update', { kind: editing.kind }),
            { threshold: Number(level), duration: Number(duration) },
            {
                preserveScroll: true,
                onSuccess: () => (teamOpen = false),
                onFinish: () => (saving = false)
            }
        )
    }

    function editServer(override: MonitorOverride | null) {
        overriding = override
        serverId = override?.server.id ?? monitors.servers[0]?.id ?? ''
        kind = override?.kind ?? serverKinds[0]?.kind ?? ''
        const monitor = monitors.monitors.find((item) => item.kind === kind)
        level = String(override?.threshold ?? monitor?.threshold ?? '')
        duration = String(override?.duration ?? monitor?.duration ?? '')
        serverOpen = true
    }

    function pickKind(value: string) {
        kind = value
        const monitor = monitors.monitors.find((item) => item.kind === value)
        level = String(monitor?.threshold ?? '')
        duration = String(monitor?.duration ?? '')
    }

    function saveServer(event: SubmitEvent) {
        event.preventDefault()
        saving = true
        router.put(
            route('settings.monitors.servers.update', { kind, server: serverId }),
            { threshold: Number(level), duration: Number(duration) },
            {
                preserveScroll: true,
                onSuccess: () => (serverOpen = false),
                onFinish: () => (saving = false)
            }
        )
    }

    function remove(override: MonitorOverride) {
        const key = `${override.server.id}:${override.kind}`
        busy = { ...busy, [key]: true }
        router.delete(route('settings.monitors.servers.destroy', { kind: override.kind, server: override.server.id }), {
            preserveScroll: true,
            onFinish: () => (busy = { ...busy, [key]: false })
        })
    }

    function nameOf(value: string) {
        return monitors.monitors.find((monitor) => monitor.kind === value)?.name ?? value
    }
</script>

<section
    aria-labelledby="monitors-title"
    class="max-w-190 overflow-hidden rounded-xl border border-border bg-card"
    data-testid="monitors-card"
>
    <div class="flex flex-wrap items-start gap-4 border-b border-border px-5.5 py-4.5">
        <div class="min-w-65 flex-1 max-sm:min-w-0">
            <h2 id="monitors-title" class="type-h3 m-0">Monitors</h2>
            <p class="mt-1 mb-0 max-w-[60ch] text-[12.5px] text-muted-foreground">
                Coresmith reads every server each minute and checks every app each 5 minutes. When one crosses a line
                for as long as you set, it emails you once, and again when it's back to normal.
            </p>
        </div>
        {#if !monitors.plan}
            <Badge variant="accent">Limits change on Pro</Badge>
        {/if}
    </div>

    {#each monitors.monitors as monitor (monitor.kind)}
        <div
            class="flex min-h-16 items-center gap-4 border-b border-border px-5.5 py-2.5 max-sm:flex-wrap max-sm:gap-3"
            data-testid="monitor-{monitor.kind}"
        >
            <div class="min-w-55 flex-1 max-sm:min-w-0">
                <div class="text-[14px] font-medium" id="monitor-{monitor.kind}-label">{monitor.name}</div>
                <div class="mt-0.5 text-[12.5px] text-muted-foreground" id="monitor-{monitor.kind}-rule">
                    {monitor.rule}
                </div>
            </div>
            <div class="flex items-center gap-3 max-sm:w-full max-sm:justify-end">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!changeable}
                    aria-label="Change the limit for {monitor.name}"
                    onclick={() => edit(monitor)}>Change</Button
                >
                <Switch
                    checked={monitor.enabled}
                    busy={busy[monitor.kind]}
                    disabled={!monitors.canChange || busy[monitor.kind]}
                    aria-labelledby="monitor-{monitor.kind}-label"
                    aria-describedby="monitor-{monitor.kind}-rule"
                    onCheckedChange={(on) => flip(monitor, on)}
                />
            </div>
        </div>
    {/each}

    <div class="flex flex-col gap-2.5 px-5.5 py-4">
        <div class="flex flex-wrap items-center gap-3">
            <h3 class="m-0 text-[14px] font-semibold">Servers with their own limits</h3>
            <Button
                variant="outline"
                size="sm"
                class="ml-auto max-sm:ml-0"
                disabled={!changeable || monitors.servers.length === 0}
                onclick={() => editServer(null)}>Set limits for a server</Button
            >
        </div>
        {#each monitors.overrides as override (`${override.server.id}:${override.kind}`)}
            <div
                class="flex min-h-12 flex-wrap items-center gap-3 border-t border-border py-1.5"
                data-testid="monitor-override"
            >
                <span class="w-27.5 truncate font-mono text-[13px] font-medium" title={override.server.name}
                    >{override.server.name}</span
                >
                <span class="min-w-50 flex-1 text-[13px] text-muted-foreground max-sm:min-w-0 max-sm:basis-full"
                    >{override.rule}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!changeable}
                    aria-label="Change the {nameOf(override.kind).toLowerCase()} limit for {override.server.name}"
                    onclick={() => editServer(override)}>Change</Button
                >
                <Button
                    variant="outline"
                    size="sm"
                    class="text-destructive"
                    disabled={!monitors.canChange || busy[`${override.server.id}:${override.kind}`]}
                    aria-label="Remove the {nameOf(override.kind).toLowerCase()} limit for {override.server.name}"
                    onclick={() => remove(override)}>Remove</Button
                >
            </div>
        {:else}
            <p class="m-0 border-t border-border pt-3 text-[13px] text-muted-foreground">
                Every server follows the limits above.
            </p>
        {/each}
        <p class="mt-1 mb-0 text-[12.5px] text-muted-foreground">{monitors.footnote}</p>
    </div>
</section>

<Dialog.Root bind:open={teamOpen}>
    <Dialog.Content class="sm:max-w-110">
        <form onsubmit={saveTeam} class="flex flex-col gap-4">
            <Dialog.Header>
                <Dialog.Title>Change the {editing?.name.toLowerCase()} monitor</Dialog.Title>
                <Dialog.Description>Every server gets this limit unless it has its own, below.</Dialog.Description>
            </Dialog.Header>
            {#if editing}
                <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                    <div>
                        <Label for="monitor-level" class="mb-1.5">{labels(editing).level}</Label>
                        <NativeSelect id="monitor-level" bind:value={level} options={options(editing.levels)} />
                    </div>
                    <div>
                        <Label for="monitor-duration" class="mb-1.5">{labels(editing).duration}</Label>
                        <NativeSelect
                            id="monitor-duration"
                            bind:value={duration}
                            options={options(editing.durations)}
                        />
                    </div>
                </div>
            {/if}
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (teamOpen = false)}>Cancel</Button>
                <Button type="submit" disabled={saving}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={serverOpen}>
    <Dialog.Content class="sm:max-w-110">
        <form onsubmit={saveServer} class="flex flex-col gap-4">
            <Dialog.Header>
                <Dialog.Title
                    >{overriding
                        ? `Change the ${nameOf(overriding.kind).toLowerCase()} limit for ${overriding.server.name}`
                        : 'Set limits for a server'}</Dialog.Title
                >
                <Dialog.Description>This server stops following the team's limit for this monitor.</Dialog.Description>
            </Dialog.Header>
            <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                <div>
                    <Label for="override-server" class="mb-1.5">Server</Label>
                    <NativeSelect
                        id="override-server"
                        bind:value={serverId}
                        disabled={overriding !== null}
                        options={monitors.servers.map((server) => ({ value: server.id, label: server.name }))}
                    />
                </div>
                <div>
                    <Label for="override-kind" class="mb-1.5">Monitor</Label>
                    <NativeSelect
                        id="override-kind"
                        value={kind}
                        disabled={overriding !== null}
                        options={serverKinds.map((monitor) => ({ value: monitor.kind, label: monitor.name }))}
                        onchange={(event) => pickKind((event.currentTarget as HTMLSelectElement).value)}
                    />
                </div>
                {#if chosen}
                    <div>
                        <Label for="override-level" class="mb-1.5">{labels(chosen).level}</Label>
                        <NativeSelect id="override-level" bind:value={level} options={options(chosen.levels)} />
                    </div>
                    <div>
                        <Label for="override-duration" class="mb-1.5">{labels(chosen).duration}</Label>
                        <NativeSelect
                            id="override-duration"
                            bind:value={duration}
                            options={options(chosen.durations)}
                        />
                    </div>
                {/if}
            </div>
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (serverOpen = false)}>Cancel</Button>
                <Button type="submit" disabled={saving || serverId === '' || kind === ''}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
