<script module lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    export type OffsiteDestination = {
        endpoint: string | null
        region: string
        bucket: string
        prefix: string | null
        path_style: boolean
        last_run: string | null
        failing: number
    }
</script>

<script lang="ts">
    import PlanNote from '@/components/settings/PlanNote.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Switch } from '@/components/ui/switch'
    import { Form, router } from '@inertiajs/svelte'

    let { offsite = null, planNote = null }: { offsite?: OffsiteDestination | null; planNote?: string | null } =
        $props()

    let editing = $state(false)
    let removing = $state(false)
    let working = $state(false)
    let pathStyle = $state(false)

    const endpointHost = $derived.by(() => {
        try {
            return offsite?.endpoint ? new URL(offsite.endpoint).host : null
        } catch {
            return offsite?.endpoint ?? null
        }
    })

    function edit() {
        pathStyle = offsite?.path_style ?? false
        editing = true
    }

    function remove() {
        working = true
        router.delete(route('settings.offsite-backups.destroy'), {
            preserveScroll: true,
            onFinish: () => ((working = false), (removing = false))
        })
    }
</script>

<div
    class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5"
    data-testid="offsite-backups-card"
>
    <div class="mb-1 flex flex-wrap items-center gap-2.5">
        <h3 class="type-h3 m-0">Database backups to S3</h3>
        {#if offsite && offsite.failing > 0}
            <Badge variant="destructive">Failing</Badge>
        {:else if offsite}
            <Badge variant="success" dot>On</Badge>
        {/if}
    </div>
    <p class="mb-3 text-[12.5px] text-muted-foreground">
        Every night at 02:30, each MySQL and PostgreSQL database goes from its server straight to your bucket. Coresmith
        never holds the dump.
    </p>
    {#if planNote}
        <PlanNote note={planNote} />
    {:else if offsite}
        <div class="flex flex-col gap-1 border-t border-border pt-3">
            <span class="truncate font-mono text-[12.5px]">s3://{offsite.bucket}/{offsite.prefix ?? ''}</span>
            <span class="truncate font-mono text-[12.5px] text-muted-foreground"
                >{offsite.region}{endpointHost ? ` · ${endpointHost}` : ''}</span
            >
            <span class="text-[12.5px] text-muted-foreground"
                >{offsite.last_run ? `last run ${offsite.last_run}` : 'not run yet'}</span
            >
        </div>
        <p class="mt-3 mb-0 text-[12.5px] text-muted-foreground">
            Set a lifecycle rule on the bucket to expire old backups.
        </p>
        <div class="mt-auto flex gap-2 pt-3">
            <Button variant="ghost" size="sm" class="max-sm:h-11" onclick={edit}>Change</Button>
            <Button variant="ghost" size="sm" class="max-sm:h-11" onclick={() => (removing = true)}>Remove</Button>
        </div>
    {:else}
        <div class="mt-auto">
            <Button variant="outline" size="sm" class="max-sm:h-11" onclick={edit}>Set up</Button>
        </div>
    {/if}
</div>

<Dialog.Root bind:open={editing}>
    <Dialog.Content class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
        <Form
            method="put"
            action={route('settings.offsite-backups.update')}
            options={{ preserveScroll: true }}
            onSuccess={() => (editing = false)}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Send databases to S3</Dialog.Title>
                    <Dialog.Description
                        >Use a bucket and keys that can only write to it. The keys are sealed and never shown again.</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4 flex flex-col gap-3">
                    <div>
                        <Label for="offsite-endpoint" class="mb-1.5">Endpoint</Label>
                        <Input
                            id="offsite-endpoint"
                            name="endpoint"
                            value={offsite?.endpoint ?? ''}
                            placeholder="Leave empty for AWS"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.endpoint ? true : undefined}
                        />
                        <FieldError message={errors.endpoint} />
                    </div>
                    <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                        <div>
                            <Label for="offsite-region" class="mb-1.5">Region</Label>
                            <Input
                                id="offsite-region"
                                name="region"
                                value={offsite?.region ?? ''}
                                required
                                placeholder="eu-central-1"
                                autocomplete="off"
                                spellcheck="false"
                                class="font-mono text-[13px]"
                                aria-invalid={errors.region ? true : undefined}
                            />
                            <FieldError message={errors.region} />
                        </div>
                        <div>
                            <Label for="offsite-bucket" class="mb-1.5">Bucket</Label>
                            <Input
                                id="offsite-bucket"
                                name="bucket"
                                value={offsite?.bucket ?? ''}
                                required
                                autocomplete="off"
                                spellcheck="false"
                                class="font-mono text-[13px]"
                                aria-invalid={errors.bucket ? true : undefined}
                            />
                            <FieldError message={errors.bucket} />
                        </div>
                    </div>
                    <div>
                        <Label for="offsite-prefix" class="mb-1.5">Path prefix</Label>
                        <Input
                            id="offsite-prefix"
                            name="prefix"
                            value={offsite?.prefix ?? ''}
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.prefix ? true : undefined}
                        />
                        <FieldError message={errors.prefix} />
                    </div>
                    <div>
                        <Label for="offsite-access-key" class="mb-1.5">Access key ID</Label>
                        <Input
                            id="offsite-access-key"
                            name="access_key_id"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.access_key_id ? true : undefined}
                        />
                        <FieldError message={errors.access_key_id} />
                    </div>
                    <div>
                        <Label for="offsite-secret" class="mb-1.5">Secret access key</Label>
                        <Input
                            id="offsite-secret"
                            name="secret_access_key"
                            type="password"
                            required={!offsite}
                            placeholder={offsite ? 'Saved, never shown' : ''}
                            autocomplete="new-password"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.secret_access_key ? true : undefined}
                        />
                        <FieldError message={errors.secret_access_key} />
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <Label id="offsite-path-style-label" for="offsite-path-style">Path-style addressing</Label>
                            <p
                                id="offsite-path-style-description"
                                class="m-0 mt-0.5 text-[12.5px] text-muted-foreground"
                            >
                                Needed for MinIO and some other S3-compatible stores.
                            </p>
                        </div>
                        <Switch
                            id="offsite-path-style"
                            bind:checked={pathStyle}
                            aria-labelledby="offsite-path-style-label"
                            aria-describedby="offsite-path-style-description"
                        />
                        <input type="hidden" name="path_style" value={pathStyle ? '1' : '0'} />
                    </div>
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (editing = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>Save</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={removing}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Stop sending databases to S3?</Dialog.Title>
            <Dialog.Description>The backups already in the bucket stay there.</Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (removing = false)}>Keep it</Button>
            <Button variant="destructive" disabled={working} onclick={remove}>Remove destination</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
