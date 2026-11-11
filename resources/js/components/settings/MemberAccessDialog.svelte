<script lang="ts" module>
    export interface MemberAccess {
        everything: boolean
        servers: string[]
        applications: string[]
    }

    export interface Shareable {
        servers: { id: string; name: string; applications: { id: string; name: string }[] }[]
    }

    export function reach(access: MemberAccess): string {
        if (access.everything) {
            return 'Sees everything'
        }

        const servers = access.servers.length
        const apps = access.applications.length
        const parts = [
            servers > 0 ? `${servers} ${servers === 1 ? 'server' : 'servers'}` : null,
            apps > 0 ? `${apps} ${apps === 1 ? 'app' : 'apps'}` : null
        ].filter((part) => part !== null)

        return parts.length > 0 ? parts.join(', ') : 'Nothing shared yet'
    }
</script>

<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import { Switch } from '@/components/ui/switch'
    import { cn } from '@/lib/utils'
    import { router } from '@inertiajs/svelte'
    import { ChevronDown } from '@lucide/svelte'
    import { untrack } from 'svelte'

    let {
        open = $bindable(false),
        team,
        member,
        shareable
    }: {
        open: boolean
        team: { id: string; name: string }
        member: { id: string; name: string; access: MemberAccess | null } | null
        shareable: Shareable
    } = $props()

    let everything = $state(true)
    let servers = $state<string[]>([])
    let applications = $state<string[]>([])
    let expanded = $state<string[]>([])
    let errors = $state<{ servers?: string; applications?: string }>({})
    let saving = $state(false)

    $effect(() => {
        if (open) {
            untrack(reset)
        }
    })

    function reset() {
        everything = member?.access?.everything ?? true
        servers = [...(member?.access?.servers ?? [])]
        applications = [...(member?.access?.applications ?? [])]
        expanded = shareable.servers
            .filter((server) => server.applications.some((application) => applications.includes(application.id)))
            .map((server) => server.id)
        errors = {}
    }

    function tick(list: string[], id: string, on: boolean): string[] {
        return on ? [...list.filter((each) => each !== id), id] : list.filter((each) => each !== id)
    }

    function save(event: SubmitEvent) {
        event.preventDefault()

        if (!member) {
            return
        }

        saving = true
        router.put(
            route('teams.members.access.update', [team.id, member.id]),
            { everything, servers, applications },
            {
                preserveScroll: true,
                onSuccess: () => (open = false),
                onError: (failed: Record<string, string>) =>
                    (errors = { servers: failed.servers, applications: failed.applications }),
                onFinish: () => (saving = false)
            }
        )
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content>
        <form onsubmit={save} novalidate data-testid="access-dialog">
            <Dialog.Header>
                <Dialog.Title>What {member?.name} can see</Dialog.Title>
                <Dialog.Description
                    >{everything
                        ? `${member?.name} sees every server and app in ${team.name}, and anything added later.`
                        : `${member?.name} sees only the servers and apps you tick. A server brings every app on it.`}</Dialog.Description
                >
            </Dialog.Header>
            <div class="mt-4 flex min-h-12 items-center gap-4 border-y border-border py-2">
                <Label for="access-everything" class="flex-1 text-[14px] font-medium">Everything in {team.name}</Label>
                <Switch id="access-everything" bind:checked={everything} />
            </div>
            {#if !everything}
                {#if shareable.servers.length === 0}
                    <p class="m-0 py-3 text-[13px] text-muted-foreground">
                        There's nothing to share in {team.name} yet. Servers show up here once someone adds one.
                    </p>
                {:else}
                    <fieldset class="m-0 max-h-[45vh] overflow-y-auto border-0 py-1.5 pr-1 pl-0">
                        <legend class="sr-only">Servers and apps to share</legend>
                        {#each shareable.servers as server (server.id)}
                            {@const whole = servers.includes(server.id)}
                            {@const shown = expanded.includes(server.id)}
                            <div class="flex min-h-10 items-center gap-2.5" data-testid="share-server">
                                <Checkbox
                                    id="share-server-{server.id}"
                                    checked={whole}
                                    onCheckedChange={(on) => (servers = tick(servers, server.id, on))}
                                    aria-invalid={errors.servers ? true : undefined}
                                />
                                <Label
                                    for="share-server-{server.id}"
                                    class="min-w-0 truncate font-mono text-[13px] font-normal">{server.name}</Label
                                >
                                {#if server.applications.length > 0}
                                    {@const count = `${server.applications.length} ${server.applications.length === 1 ? 'app' : 'apps'}`}
                                    <button
                                        type="button"
                                        class="focus-ring ml-auto inline-flex h-8 shrink-0 cursor-pointer items-center gap-1 rounded-md bg-transparent px-2 text-[12px] text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted"
                                        aria-expanded={shown}
                                        aria-controls="share-apps-{server.id}"
                                        aria-label="{count} on {server.name}"
                                        onclick={() => (expanded = tick(expanded, server.id, !shown))}
                                    >
                                        {count}
                                        <ChevronDown
                                            class={cn(
                                                'size-3.5 transition-transform duration-(--dur-fast)',
                                                shown && 'rotate-180'
                                            )}
                                        />
                                    </button>
                                {/if}
                            </div>
                            {#if shown}
                                <div id="share-apps-{server.id}" role="group" aria-label="Apps on {server.name}">
                                    {#each server.applications as application (application.id)}
                                        <div class="flex min-h-9 items-center gap-2.5 pl-6.5" data-testid="share-app">
                                            <Checkbox
                                                id="share-app-{application.id}"
                                                checked={whole || applications.includes(application.id)}
                                                disabled={whole}
                                                onCheckedChange={(on) =>
                                                    (applications = tick(applications, application.id, on))}
                                                aria-invalid={errors.applications ? true : undefined}
                                            />
                                            <Label
                                                for="share-app-{application.id}"
                                                class="min-w-0 truncate font-mono text-[13px] font-normal"
                                                >{application.name}</Label
                                            >
                                        </div>
                                    {/each}
                                </div>
                            {/if}
                        {/each}
                    </fieldset>
                    <FieldError message={errors.servers ?? errors.applications} />
                {/if}
            {/if}
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                <Button type="submit" disabled={saving}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
