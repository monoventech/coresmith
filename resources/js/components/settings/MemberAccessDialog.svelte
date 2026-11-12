<script lang="ts" module>
    export interface MemberAccess {
        everything: boolean
        servers: string[]
        applications: string[]
        projects: string[]
        environments: string[]
    }

    export interface Shareable {
        servers: { id: string; name: string; applications: { id: string; name: string }[] }[]
        projects: { id: string; name: string; environments: { id: string; name: string }[] }[]
    }

    function count(total: number, one: string, many: string): string | null {
        return total > 0 ? `${total} ${total === 1 ? one : many}` : null
    }

    export function reach(access: MemberAccess): string {
        if (access.everything) {
            return 'Sees everything'
        }

        const parts = [
            count(access.projects.length, 'project', 'projects'),
            count(access.environments.length, 'environment', 'environments'),
            count(access.servers.length, 'server', 'servers'),
            count(access.applications.length, 'app', 'apps')
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
    let projects = $state<string[]>([])
    let environments = $state<string[]>([])
    let expanded = $state<string[]>([])
    let errors = $state<{ servers?: string; applications?: string; projects?: string; environments?: string }>({})
    let saving = $state(false)

    const nothing = $derived(shareable.servers.length === 0 && shareable.projects.length === 0)
    const toggle =
        'focus-ring ml-auto inline-flex h-8 shrink-0 cursor-pointer items-center gap-1 rounded-md bg-transparent px-2 text-[12px] text-muted-foreground transition-colors duration-(--dur-fast) hover:bg-muted'

    $effect(() => {
        if (open) {
            untrack(reset)
        }
    })

    function reset() {
        everything = member?.access?.everything ?? true
        servers = [...(member?.access?.servers ?? [])]
        applications = [...(member?.access?.applications ?? [])]
        projects = [...(member?.access?.projects ?? [])]
        environments = [...(member?.access?.environments ?? [])]
        expanded = [
            ...shareable.servers
                .filter((server) => server.applications.some((application) => applications.includes(application.id)))
                .map((server) => server.id),
            ...shareable.projects
                .filter((project) => project.environments.some((environment) => environments.includes(environment.id)))
                .map((project) => project.id)
        ]
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
            { everything, servers, applications, projects, environments },
            {
                preserveScroll: true,
                onSuccess: () => (open = false),
                onError: (failed: Record<string, string>) =>
                    (errors = {
                        servers: failed.servers,
                        applications: failed.applications,
                        projects: failed.projects,
                        environments: failed.environments
                    }),
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
                        ? `${member?.name} sees every project, server and app in ${team.name}, and anything added later.`
                        : `${member?.name} sees only what you tick. A project brings every environment in it, and a server every app on it.`}</Dialog.Description
                >
            </Dialog.Header>
            <div class="mt-4 flex min-h-12 items-center gap-4 border-y border-border py-2">
                <Label for="access-everything" class="flex-1 text-[14px] font-medium">Everything in {team.name}</Label>
                <Switch id="access-everything" bind:checked={everything} />
            </div>
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
                                    {@const whole = projects.includes(project.id)}
                                    {@const shown = expanded.includes(project.id)}
                                    <div class="flex min-h-10 items-center gap-2.5" data-testid="share-project">
                                        <Checkbox
                                            id="share-project-{project.id}"
                                            checked={whole}
                                            onCheckedChange={(on) => (projects = tick(projects, project.id, on))}
                                            aria-invalid={errors.projects ? true : undefined}
                                        />
                                        <Label
                                            for="share-project-{project.id}"
                                            class="min-w-0 truncate text-[13px] font-normal">{project.name}</Label
                                        >
                                        {#if project.environments.length > 0}
                                            {@const environmentCount = `${project.environments.length} ${project.environments.length === 1 ? 'environment' : 'environments'}`}
                                            <button
                                                type="button"
                                                class={toggle}
                                                aria-expanded={shown}
                                                aria-controls="share-environments-{project.id}"
                                                aria-label="{environmentCount} in {project.name}"
                                                onclick={() => (expanded = tick(expanded, project.id, !shown))}
                                            >
                                                {environmentCount}
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
                                            id="share-environments-{project.id}"
                                            role="group"
                                            aria-label="Environments in {project.name}"
                                        >
                                            {#each project.environments as environment (environment.id)}
                                                <div
                                                    class="flex min-h-9 items-center gap-2.5 pl-6.5"
                                                    data-testid="share-environment"
                                                >
                                                    <Checkbox
                                                        id="share-environment-{environment.id}"
                                                        checked={whole || environments.includes(environment.id)}
                                                        disabled={whole}
                                                        onCheckedChange={(on) =>
                                                            (environments = tick(environments, environment.id, on))}
                                                        aria-invalid={errors.environments ? true : undefined}
                                                    />
                                                    <Label
                                                        for="share-environment-{environment.id}"
                                                        class="min-w-0 truncate text-[13px] font-normal"
                                                        >{environment.name}</Label
                                                    >
                                                </div>
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
                                    <div class="flex min-h-10 items-center gap-2.5" data-testid="share-server">
                                        <Checkbox
                                            id="share-server-{server.id}"
                                            checked={whole}
                                            onCheckedChange={(on) => (servers = tick(servers, server.id, on))}
                                            aria-invalid={errors.servers ? true : undefined}
                                        />
                                        <Label
                                            for="share-server-{server.id}"
                                            class="min-w-0 truncate font-mono text-[13px] font-normal"
                                            >{server.name}</Label
                                        >
                                        {#if server.applications.length > 0}
                                            {@const appCount = `${server.applications.length} ${server.applications.length === 1 ? 'app' : 'apps'}`}
                                            <button
                                                type="button"
                                                class={toggle}
                                                aria-expanded={shown}
                                                aria-controls="share-apps-{server.id}"
                                                aria-label="{appCount} on {server.name}"
                                                onclick={() => (expanded = tick(expanded, server.id, !shown))}
                                            >
                                                {appCount}
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
                                            id="share-apps-{server.id}"
                                            role="group"
                                            aria-label="Apps on {server.name}"
                                        >
                                            {#each server.applications as application (application.id)}
                                                <div
                                                    class="flex min-h-9 items-center gap-2.5 pl-6.5"
                                                    data-testid="share-app"
                                                >
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
                        {/if}
                    </div>
                    <FieldError
                        message={errors.projects ?? errors.environments ?? errors.servers ?? errors.applications}
                    />
                {/if}
            {/if}
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                <Button type="submit" disabled={saving}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
