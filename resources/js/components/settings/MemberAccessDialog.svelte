<script lang="ts" module>
    export interface MemberAccess {
        everything: boolean
        servers: string[]
        applications: string[]
        databases: string[]
        projects: string[]
        withheld_applications?: string[]
        withheld_databases?: string[]
    }

    export type ShareableItem = {
        id: string
        name: string
        tags?: string[]
        developer: { id: string; name: string } | null
    }

    export interface Shareable {
        servers: {
            id: string
            name: string
            this_machine: boolean
            applications: ShareableItem[]
            databases: ShareableItem[]
        }[]
        projects: { id: string; name: string; applications: ShareableItem[]; databases: ShareableItem[] }[]
    }

    function count(total: number, one: string, many: string): string | null {
        return total > 0 ? `${total} ${total === 1 ? one : many}` : null
    }

    export function reach(access: MemberAccess): string {
        if (access.everything) {
            return 'Sees everything'
        }

        const held = (access.withheld_applications?.length ?? 0) + (access.withheld_databases?.length ?? 0)
        const parts = [
            count(access.projects.length, 'project', 'projects'),
            count(access.servers.length, 'server', 'servers'),
            count(access.applications.length, 'app', 'apps'),
            count(access.databases?.length ?? 0, 'database', 'databases'),
            count(held, 'held back', 'held back')
        ].filter((part) => part !== null)

        return parts.length > 0 ? parts.join(', ') : 'Nothing shared yet'
    }
</script>

<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import AppTags from '@/components/projects/AppTags.svelte'
    import { Button } from '@/components/ui/button'
    import { Checkbox } from '@/components/ui/checkbox'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import { Switch } from '@/components/ui/switch'
    import { cn } from '@/lib/utils'
    import { router } from '@inertiajs/svelte'
    import { ChevronDown } from '@lucide/svelte'
    import { untrack } from 'svelte'

    type Kind = 'applications' | 'databases'
    type SharedServer = Shareable['servers'][number]
    type SharedProject = Shareable['projects'][number]
    type Spot = { item: ShareableItem; server: SharedServer | null; project: string | null }

    let {
        open = $bindable(false),
        team,
        member,
        shareable
    }: {
        open: boolean
        team: { id: string; name: string }
        member: { id: string; name: string; role?: string; access: MemberAccess | null } | null
        shareable: Shareable
    } = $props()

    let everything = $state(true)
    let servers = $state<string[]>([])
    let projects = $state<string[]>([])
    let direct = $state<Record<Kind, string[]>>({ applications: [], databases: [] })
    let withheld = $state<Record<Kind, string[]>>({ applications: [], databases: [] })
    let expanded = $state<string[]>([])
    let errors = $state<Record<string, string | undefined>>({})
    let saving = $state(false)

    const developer = $derived(member?.role === 'developer')
    const nothing = $derived(shareable.servers.length === 0 && shareable.projects.length === 0)
    const spots = $derived.by(() => {
        const found: Record<Kind, Map<string, Spot>> = { applications: new Map(), databases: new Map() }

        for (const server of shareable.servers) {
            for (const kind of ['applications', 'databases'] as const) {
                for (const item of server[kind]) {
                    found[kind].set(item.id, { item, server, project: found[kind].get(item.id)?.project ?? null })
                }
            }
        }

        for (const project of shareable.projects) {
            for (const kind of ['applications', 'databases'] as const) {
                for (const item of project[kind]) {
                    found[kind].set(item.id, {
                        item,
                        server: found[kind].get(item.id)?.server ?? null,
                        project: project.id
                    })
                }
            }
        }

        return found
    })
    const keptByDevelopers = $derived(
        [...spots.applications.values(), ...spots.databases.values()].some((spot) => kept(spot.item))
    )
    const toggle =
        'focus-ring ml-auto inline-flex h-8 shrink-0 cursor-pointer items-center gap-1 rounded-md bg-transparent px-2 text-[12px] text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted'

    $effect(() => {
        if (open) {
            untrack(reset)
        }
    })

    function reset() {
        everything = developer ? false : (member?.access?.everything ?? true)
        servers = [...(member?.access?.servers ?? [])]
        projects = [...(member?.access?.projects ?? [])]
        direct = {
            applications: [...(member?.access?.applications ?? [])],
            databases: [...(member?.access?.databases ?? [])]
        }
        withheld = {
            applications: [...(member?.access?.withheld_applications ?? [])],
            databases: [...(member?.access?.withheld_databases ?? [])]
        }
        expanded = [...shareable.projects, ...shareable.servers]
            .filter((container) => partly(container, containerChosen(container)))
            .map((container) => container.id)
        errors = {}
    }

    function tick(list: string[], id: string, on: boolean): string[] {
        return on ? [...list.filter((each) => each !== id), id] : list.filter((each) => each !== id)
    }

    function signsIn(server: SharedServer): boolean {
        return member?.role === 'admin' && !server.this_machine
    }

    function mine(item: ShareableItem): boolean {
        return developer && item.developer?.id === member?.id
    }

    function kept(item: ShareableItem): boolean {
        return item.developer !== null && item.developer.id !== member?.id
    }

    function locked(spot: Spot): boolean {
        return spot.server !== null && servers.includes(spot.server.id) && signsIn(spot.server)
    }

    function covered(spot: Spot): boolean {
        return (
            (spot.server !== null && servers.includes(spot.server.id) && !developer) ||
            (spot.project !== null && projects.includes(spot.project))
        )
    }

    function spotOf(kind: Kind, id: string): Spot | undefined {
        return spots[kind].get(id)
    }

    function shared(kind: Kind, id: string): boolean {
        const spot = spotOf(kind, id)

        if (!spot) {
            return false
        }

        if (mine(spot.item)) {
            return true
        }

        if (kept(spot.item)) {
            return direct[kind].includes(id)
        }

        if (locked(spot)) {
            return true
        }

        return covered(spot) ? !withheld[kind].includes(id) : direct[kind].includes(id)
    }

    function fixed(kind: Kind, id: string): boolean {
        const spot = spotOf(kind, id)

        return spot !== undefined && (mine(spot.item) || (!kept(spot.item) && locked(spot)))
    }

    function items(container: SharedServer | SharedProject): [Kind, ShareableItem][] {
        return [
            ...container.applications.map((item): [Kind, ShareableItem] => ['applications', item]),
            ...container.databases.map((item): [Kind, ShareableItem] => ['databases', item])
        ]
    }

    function containerChosen(container: SharedServer | SharedProject): boolean {
        return 'this_machine' in container ? servers.includes(container.id) : projects.includes(container.id)
    }

    function partly(container: SharedServer | SharedProject, chosen: boolean): boolean {
        const all = items(container)
        const on = all.filter(([kind, item]) => shared(kind, item.id)).length

        return chosen ? on < all.length : on > 0
    }

    function contents(container: SharedServer | SharedProject): string {
        const all = items(container)
        const on = all.filter(([kind, item]) => shared(kind, item.id)).length

        if (all.length > 0 && partly(container, containerChosen(container))) {
            return `${on} of ${all.length} shared`
        }

        return [
            count(container.applications.length, 'app', 'apps'),
            count(container.databases.length, 'database', 'databases')
        ]
            .filter((part) => part !== null)
            .join(' · ')
    }

    function share(kind: Kind, id: string, on: boolean, project: SharedProject | null) {
        const spot = spotOf(kind, id)

        if (!spot || fixed(kind, id)) {
            return
        }

        if (kept(spot.item)) {
            direct = { ...direct, [kind]: tick(direct[kind], id, on) }

            return
        }

        if (covered(spot)) {
            withheld = { ...withheld, [kind]: tick(withheld[kind], id, !on) }
            direct = { ...direct, [kind]: tick(direct[kind], id, false) }

            return
        }

        if (on && project !== null) {
            const hidden = items(project).filter(
                ([each, item]) => !(each === kind && item.id === id) && !kept(item) && !shared(each, item.id)
            )
            const next = { applications: [...withheld.applications], databases: [...withheld.databases] }

            for (const [each, item] of hidden) {
                next[each] = tick(next[each], item.id, true)
            }

            next[kind] = tick(next[kind], id, false)
            withheld = next
            direct = { ...direct, [kind]: tick(direct[kind], id, false) }
            projects = tick(projects, project.id, true)

            return
        }

        direct = { ...direct, [kind]: tick(direct[kind], id, on) }
    }

    function choose(container: SharedServer | SharedProject, on: boolean) {
        if ('this_machine' in container) {
            servers = tick(servers, container.id, on)
        } else {
            projects = tick(projects, container.id, on)
        }

        if (on && !('this_machine' in container && developer)) {
            withheld = {
                applications: withheld.applications.filter(
                    (id) => !container.applications.some((item) => item.id === id)
                ),
                databases: withheld.databases.filter((id) => !container.databases.some((item) => item.id === id))
            }
        }
    }

    function held(kind: Kind): string[] {
        return withheld[kind].filter((id) => {
            const spot = spotOf(kind, id)

            return spot !== undefined && !kept(spot.item) && !mine(spot.item) && covered(spot) && !locked(spot)
        })
    }

    function save(event: SubmitEvent) {
        event.preventDefault()

        if (!member) {
            return
        }

        saving = true
        router.put(
            route('teams.members.access.update', [team.id, member.id]),
            {
                everything,
                servers,
                projects,
                applications: direct.applications.filter((id) => !held('applications').includes(id)),
                databases: direct.databases.filter((id) => !held('databases').includes(id)),
                withheld_applications: held('applications'),
                withheld_databases: held('databases')
            },
            {
                preserveScroll: true,
                onSuccess: () => (open = false),
                onError: (failed: Record<string, string>) => (errors = failed),
                onFinish: () => (saving = false)
            }
        )
    }

    const failure = $derived(
        errors.projects ??
            errors.servers ??
            errors.applications ??
            errors.databases ??
            errors.withheld_applications ??
            errors.withheld_databases
    )
</script>

{#snippet row(kind: Kind, item: ShareableItem, project: SharedProject | null, prefix: string)}
    <div
        class="flex min-h-9 items-center gap-2.5 pl-6.5"
        data-testid={kind === 'applications' ? 'share-app' : 'share-database'}
    >
        <Checkbox
            id="{prefix}-{item.id}"
            checked={shared(kind, item.id)}
            disabled={fixed(kind, item.id)}
            onCheckedChange={(on) => share(kind, item.id, on, project)}
            aria-invalid={failure ? true : undefined}
        />
        <Label for="{prefix}-{item.id}" class="min-w-0 truncate font-mono text-[13px] font-normal">{item.name}</Label>
        {#if item.tags && item.tags.length > 0}
            <AppTags tags={item.tags} limit={2} class="shrink-0 flex-nowrap" chipClass="max-w-20" />
        {/if}
        <span class="ml-auto shrink-0 pr-2 text-[12px] text-muted-foreground"
            >{mine(item)
                ? 'Theirs'
                : item.developer
                  ? `Added by ${item.developer.name}`
                  : kind === 'databases'
                    ? 'Database'
                    : ''}</span
        >
    </div>
{/snippet}

<Dialog.Root bind:open>
    <Dialog.Content>
        <form onsubmit={save} novalidate data-testid="access-dialog">
            <Dialog.Header>
                <Dialog.Title>What {member?.name} can see</Dialog.Title>
                <Dialog.Description
                    >{everything
                        ? `${member?.name} sees every project, server and app in ${team.name}, and anything added later${keptByDevelopers ? ', apart from what developers add for themselves' : ''}.`
                        : developer
                          ? `${member?.name} sees what they add and what you tick. A project brings everything in it apart from what you untick. A server lets ${member?.name} add apps and databases to it and shows only what you tick under it.`
                          : `${member?.name} sees only what you tick. A project or a server brings everything in it apart from what you untick.`}</Dialog.Description
                >
            </Dialog.Header>
            {#if developer}
                <div class="mt-4 border-t border-border"></div>
            {:else}
                <div class="mt-4 flex min-h-12 items-center gap-4 border-y border-border py-2">
                    <Label for="access-everything" class="flex-1 text-[14px] font-medium"
                        >Everything in {team.name}</Label
                    >
                    <Switch id="access-everything" bind:checked={everything} />
                </div>
            {/if}
            {#if !everything}
                {#if nothing}
                    <p class="m-0 py-3 text-[13px] text-muted-foreground">
                        There's nothing to share in {team.name} yet. Projects and servers show up here once someone adds one.
                    </p>
                {:else}
                    <div class="max-h-[45vh] overflow-y-auto py-1.5 pr-1">
                        {#if shareable.projects.length > 0}
                            <fieldset class="m-0 border-0 p-0">
                                <legend class="type-label pt-1.5 text-muted-foreground">Projects</legend>
                                {#each shareable.projects as project (project.id)}
                                    {@const shown = expanded.includes(project.id)}
                                    {@const listed = contents(project)}
                                    <div class="flex min-h-10 items-center gap-2.5" data-testid="share-project">
                                        <Checkbox
                                            id="share-project-{project.id}"
                                            checked={projects.includes(project.id)}
                                            onCheckedChange={(on) => choose(project, on)}
                                            aria-invalid={errors.projects ? true : undefined}
                                        />
                                        <Label
                                            for="share-project-{project.id}"
                                            class="min-w-0 truncate text-[13px] font-normal">{project.name}</Label
                                        >
                                        {#if listed !== ''}
                                            <button
                                                type="button"
                                                class={toggle}
                                                aria-expanded={shown}
                                                aria-controls="share-project-items-{project.id}"
                                                aria-label="{listed} in {project.name}"
                                                onclick={() => (expanded = tick(expanded, project.id, !shown))}
                                            >
                                                {listed}
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
                                        <div
                                            id="share-project-items-{project.id}"
                                            role="group"
                                            aria-label="In {project.name}"
                                        >
                                            {#each project.applications as item (item.id)}
                                                {@render row('applications', item, project, 'share-project-app')}
                                            {/each}
                                            {#each project.databases as item (item.id)}
                                                {@render row('databases', item, project, 'share-project-database')}
                                            {/each}
                                        </div>
                                    {/if}
                                {/each}
                            </fieldset>
                        {/if}
                        {#if shareable.servers.length > 0}
                            <fieldset class="m-0 border-0 p-0">
                                <legend
                                    class={shareable.projects.length > 0
                                        ? 'type-label pt-3 text-muted-foreground'
                                        : 'sr-only'}>Servers and apps to share</legend
                                >
                                {#each shareable.servers as server (server.id)}
                                    {@const whole = servers.includes(server.id)}
                                    {@const shown = expanded.includes(server.id)}
                                    {@const listed = contents(server)}
                                    <div class="flex min-h-10 items-center gap-2.5" data-testid="share-server">
                                        <Checkbox
                                            id="share-server-{server.id}"
                                            checked={whole}
                                            onCheckedChange={(on) => choose(server, on)}
                                            aria-invalid={errors.servers ? true : undefined}
                                        />
                                        <Label
                                            for="share-server-{server.id}"
                                            class="min-w-0 truncate font-mono text-[13px] font-normal"
                                            >{server.name}</Label
                                        >
                                        {#if listed !== ''}
                                            <button
                                                type="button"
                                                class={toggle}
                                                aria-expanded={shown}
                                                aria-controls="share-apps-{server.id}"
                                                aria-label="{listed} on {server.name}"
                                                onclick={() => (expanded = tick(expanded, server.id, !shown))}
                                            >
                                                {listed}
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
                                        <div id="share-apps-{server.id}" role="group" aria-label="On {server.name}">
                                            {#if whole && signsIn(server)}
                                                <p
                                                    class="m-0 pt-1 pb-2 pl-6.5 text-[12px] leading-normal text-muted-foreground"
                                                    data-testid="share-signs-in"
                                                >
                                                    {member?.name} signs in to {server.name} over SSH, which reaches everything
                                                    on it, so it all comes with the server. To share only some of its apps,
                                                    untick {server.name} and tick them.
                                                </p>
                                            {/if}
                                            {#each server.applications as item (item.id)}
                                                {@render row('applications', item, null, 'share-app')}
                                            {/each}
                                            {#each server.databases as item (item.id)}
                                                {@render row('databases', item, null, 'share-database')}
                                            {/each}
                                        </div>
                                    {/if}
                                {/each}
                            </fieldset>
                        {/if}
                    </div>
                    <FieldError message={failure} />
                {/if}
            {/if}
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                <Button type="submit" disabled={saving}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
