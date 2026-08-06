<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import Heading from '@/components/Heading.svelte'
    import StatusBadge from '@/components/StatusBadge.svelte'
    import { Button } from '@/components/ui/button'
    import { Link, router } from '@inertiajs/svelte'

    interface Deployment {
        id: string
        status: string | null
        commit_hash: string | null
        commit_message: string | null
        finished_at: string | null
    }

    let {
        server,
        application,
        deployments = [],
        can
    }: {
        server: { id: string; name: string }
        application: Record<string, any>
        deployments: Deployment[]
        can: { update: boolean; delete: boolean; deploy: boolean; manageEnvironment: boolean }
    } = $props()

    const base = $derived(`/servers/${server.id}/applications/${application.id}`)
</script>

<AppHead title={application.name} />

<div class="px-6 py-8">
    <div class="mb-10 flex items-start justify-between gap-6">
        <Heading title={application.name} description={application.domain ?? 'No domain assigned yet'} />

        <div class="flex items-center gap-3">
            <StatusBadge status={application.status} />
            {#if can.manageEnvironment}
                <Link href="{base}/env"><Button variant="outline">Environment</Button></Link>
            {/if}
            {#if can.deploy}
                <Button variant="outline" onclick={() => router.post(`${base}/rollback`)}>Roll back</Button>
                <Button onclick={() => router.post(`${base}/deploy`)}>Deploy</Button>
            {/if}
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-border bg-card p-6">
            <h3 class="mb-4 text-base font-semibold">Source</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Repository</dt>
                    <dd class="truncate font-mono text-xs">{application.repository_url ?? 'Not set'}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Branch</dt>
                    <dd class="font-mono text-xs">{application.repository_branch}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Web root</dt>
                    <dd class="font-mono text-xs">{application.web_directory}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-xl border border-border bg-card p-6">
            <h3 class="mb-4 text-base font-semibold">Runtime</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Stack</dt>
                    <dd>{application.framework ?? application.type}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Size</dt>
                    <dd>{application.app_size}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Port block</dt>
                    <dd class="font-mono text-xs">{application.base_port}–{application.base_port + 9}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Migrations on deploy</dt>
                    <dd>{application.run_migrations ? 'Yes' : 'No'}</dd>
                </div>
            </dl>
        </section>
    </div>

    {#if application.uses_deploy_key && application.deploy_key_public}
        <section class="mt-6 rounded-xl border border-border bg-card p-6">
            <h3 class="mb-4 text-base font-semibold">Deploy key</h3>
            <p class="mb-4 text-sm leading-relaxed text-muted-foreground">
                Add this as a read-only deploy key on the repository. It is used only for cloning, and only for this
                application — no access token is ever used for git.
            </p>
            <pre class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">{application.deploy_key_public}</pre>
        </section>
    {/if}

    <section class="mt-6 rounded-xl border border-border bg-card p-6">
        <h3 class="mb-4 text-base font-semibold">Recent deployments</h3>

        {#if deployments.length === 0}
            <p class="text-sm text-muted-foreground">Nothing deployed yet.</p>
        {:else}
            <ul class="divide-y divide-border">
                {#each deployments as deployment (deployment.id)}
                    <li>
                        <Link
                            href={route('servers.applications.deployments.show', [server.id, application.id, deployment.id])}
                            class="flex items-center justify-between gap-4 rounded-md py-3 outline-none hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm">{deployment.commit_message ?? 'No commit message'}</p>
                                <p class="font-mono text-xs text-muted-foreground">{deployment.commit_hash?.slice(0, 8) ?? '—'}</p>
                            </div>
                            <StatusBadge status={deployment.status} />
                        </Link>
                    </li>
                {/each}
            </ul>
        {/if}
    </section>
</div>
