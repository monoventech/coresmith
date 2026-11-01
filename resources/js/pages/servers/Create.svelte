<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'

    let {
        teams = [],
        environments = [],
        phases = []
    }: {
        teams: { id: string; name: string }[]
        environments: string[]
        phases: { name: string; desc: string }[]
    } = $props()
</script>

<AppHead title="Provision a server" />

<BackLink href={route('dashboard')} label="Fleet" />

<h1 class="type-h1 m-0 mb-1.5">Provision a <MarkerUnderline>server</MarkerUnderline></h1>
<p class="type-body-sm mb-7 text-muted-foreground">About six minutes from blank VM to hardened, ready-to-deploy host. Every phase is logged.</p>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card p-6">
        <Form method="post" action={route('servers.store')}>
            {#snippet children({ errors, processing })}
                <div class="flex flex-col gap-4">
                    <div>
                        <Label for="name" class="mb-1.5">Server name</Label>
                        <Input id="name" name="name" required placeholder="outpost-02" autocomplete="off" aria-invalid={errors.name ? true : undefined} />
                        <FieldError message={errors.name} />
                    </div>
                    <div>
                        <Label for="ip_address" class="mb-1.5">IP address</Label>
                        <Input id="ip_address" name="ip_address" required placeholder="203.0.113.40" autocomplete="off" aria-invalid={errors.ip_address ? true : undefined} />
                        <FieldError message={errors.ip_address} />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label for="ssh_port" class="mb-1.5">SSH port</Label>
                            <Input id="ssh_port" name="ssh_port" type="number" value="22" min="1" max="65535" required aria-invalid={errors.ssh_port ? true : undefined} />
                            <FieldError message={errors.ssh_port} />
                        </div>
                        <div>
                            <Label for="ssh_user" class="mb-1.5">SSH user</Label>
                            <Input id="ssh_user" name="ssh_user" value="root" required aria-invalid={errors.ssh_user ? true : undefined} />
                            <FieldError message={errors.ssh_user} />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label for="environment" class="mb-1.5">Environment</Label>
                            <NativeSelect id="environment" name="environment" options={environments} value="Production" />
                        </div>
                        <div>
                            <Label for="region" class="mb-1.5">Region</Label>
                            <Input id="region" name="region" placeholder="fra1" aria-invalid={errors.region ? true : undefined} />
                            <FieldError message={errors.region} />
                        </div>
                    </div>
                    {#if teams.length > 1}
                        <div>
                            <Label for="team_id" class="mb-1.5">Team</Label>
                            <NativeSelect id="team_id" name="team_id" options={teams.map((team) => ({ value: team.id, label: team.name }))} value={teams[0]?.id} />
                        </div>
                    {:else}
                        <input type="hidden" name="team_id" value={teams[0]?.id ?? ''} />
                    {/if}
                    <p class="-mt-1 text-[12px] text-muted-foreground">
                        Needs Ubuntu 24.04 or Debian 12 and SSH access. Coresmith generates a key for this server alone, you add it, then it provisions.
                    </p>
                    <FieldError message={errors.team_id} />
                    <Button type="submit" class="w-full" disabled={processing}>Add server</Button>
                </div>
            {/snippet}
        </Form>
    </div>

    <div class="rounded-xl border border-border bg-card p-6">
        <h3 class="type-h3 m-0 mb-1">What happens next</h3>
        <p class="mb-4 text-[12.5px] text-muted-foreground">Eight phases, hardened by default, not opt-in.</p>
        <ol class="m-0 flex list-none flex-col gap-0.5 p-0">
            {#each phases as phase, index (phase.name)}
                <li class="flex items-baseline gap-3 py-1.75">
                    <span class="w-4 flex-none font-mono text-[12px] text-primary-strong">{index + 1}</span>
                    <span class="text-[13.5px]"><span class="font-medium">{phase.name}</span><span class="text-muted-foreground">{`, ${phase.desc}`}</span></span>
                </li>
            {/each}
        </ol>
    </div>
</div>
