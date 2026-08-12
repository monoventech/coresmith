<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import ApplicationOfflineBanner from '@/components/ApplicationOfflineBanner.svelte'
    import Heading from '@/components/Heading.svelte'
    import ApplicationTabs from '@/components/ApplicationTabs.svelte'
    import { Label } from '@/components/ui/label'
    import { Switch } from '@/components/ui/switch'

    let {
        server,
        application,
        can
    }: {
        server: { id: string; name: string }
        application: {
            id: string
            name: string
            power_state: string | null
            toggles: Record<string, boolean>
            processes: { name: string; command?: string }[] | null
            cron_jobs: { schedule?: string; command?: string }[] | null
        }
        can: { update: boolean; manageEnvironment: boolean }
    } = $props()

    const base = $derived(`/servers/${server.id}/applications/${application.id}`)
    const stopped = $derived(application.power_state === 'stopped')
    const maintenance = $derived(Boolean(application.toggles?.maintenance_mode))
</script>

<AppHead title="{application.name} features" />

<div class="px-6 py-8">
    <Heading title={application.name} description="What this application runs, and how it behaves." variant="small" />

    <ApplicationTabs serverId={server.id} applicationId={application.id} canManageEnvironment={can.manageEnvironment} canUpdate={can.update} />

    <ApplicationOfflineBanner {stopped} {maintenance} />

    <section class="max-w-2xl">
        <h2 class="text-xl font-semibold tracking-[-0.01em]">Site settings</h2>

        <div class="mt-4 rounded-xl border border-border bg-card p-5">
            <div class="flex items-start justify-between gap-6">
                <div>
                    <Label for="maintenance-mode" class="text-sm font-medium">Maintenance page</Label>
                    <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                        Serve a holding page to visitors while leaving your processes running, so you can still reach
                        the app over SSH to debug it.
                    </p>
                    {#if stopped}
                        <p class="mt-2 text-xs text-muted-foreground">
                            The application is stopped, so visitors already see one.
                        </p>
                    {/if}
                </div>
                {#if can.update}
                    <Switch
                        id="maintenance-mode"
                        checked={maintenance}
                        disabled={stopped}
                        onCheckedChange={(enabled) => router.put(`${base}/maintenance`, { enabled }, { preserveScroll: true })}
                    />
                {/if}
            </div>
        </div>
    </section>

    <section class="mt-10 max-w-2xl">
        <h2 class="text-xl font-semibold tracking-[-0.01em]">Processes</h2>

        {#if application.processes && application.processes.length > 0}
            <ul class="mt-4 divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                {#each application.processes as process (process.name)}
                    <li class="px-5 py-3">
                        <p class="text-sm font-medium">{process.name}</p>
                        {#if process.command}
                            <p class="mt-0.5 font-mono text-xs text-muted-foreground">{process.command}</p>
                        {/if}
                    </li>
                {/each}
            </ul>
        {:else}
            <p class="mt-4 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
                No long-running processes. Coresmith detects queue workers and schedulers when it deploys.
            </p>
        {/if}
    </section>

    <section class="mt-10 max-w-2xl">
        <h2 class="text-xl font-semibold tracking-[-0.01em]">Scheduled commands</h2>

        {#if application.cron_jobs && application.cron_jobs.length > 0}
            <ul class="mt-4 divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                {#each application.cron_jobs as job (job.command)}
                    <li class="px-5 py-3">
                        <p class="font-mono text-xs">{job.schedule}</p>
                        <p class="mt-0.5 font-mono text-xs text-muted-foreground">{job.command}</p>
                    </li>
                {/each}
            </ul>
        {:else}
            <p class="mt-4 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
                Nothing scheduled.
            </p>
        {/if}
    </section>
</div>
