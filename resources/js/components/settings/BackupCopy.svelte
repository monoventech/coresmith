<script module lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import type { S3Destination } from '@/components/settings/S3DestinationFields.svelte'

    export type BackupCopyState = S3Destination & {
        last: string | null
        error: string | null
    }
</script>

<script lang="ts">
    import S3DestinationFields from '@/components/settings/S3DestinationFields.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Form, router } from '@inertiajs/svelte'

    let { copy = null, canManage }: { copy?: BackupCopyState | null; canManage: boolean } = $props()

    let editing = $state(false)
    let stopping = $state(false)
    let working = $state(false)
    let pathStyle = $state(false)

    function edit() {
        pathStyle = copy?.path_style ?? false
        editing = true
    }

    function stop() {
        working = true
        router.delete(route('settings.backups.copy.destroy'), {
            preserveScroll: true,
            onFinish: () => ((working = false), (stopping = false))
        })
    }
</script>

<div class="mt-3 border-t border-border pt-3 text-[12.5px]" data-testid="backup-copy">
    {#if copy}
        <div class="font-mono leading-[1.9] text-muted-foreground">
            <div class="truncate">copied to, s3://{copy.bucket}/{copy.prefix ?? ''}</div>
            {#if copy.error === null}
                <div>{copy.last ? `last copy, ${copy.last}` : 'no copy yet'}</div>
            {/if}
        </div>
        {#if copy.error}
            <p class="m-0 mt-1.5 wrap-anywhere text-destructive-strong" role="alert" data-testid="backup-copy-error">
                The last copy didn't go through. {copy.error}
            </p>
        {/if}
        <p class="m-0 mt-2 text-muted-foreground" data-testid="backup-copy-keys">
            Copies leave out Coresmith's keys. Keep <span class="font-mono text-[12px]">APP_KEY</span> and
            <span class="font-mono text-[12px]">CORESMITH_VAULT_KEY</span>, from
            <span class="font-mono text-[12px] wrap-anywhere">/opt/coresmith/shared/.env</span>, in your password
            manager. You need both to restore a copy.
        </p>
    {:else}
        <p class="m-0 text-muted-foreground">
            Backups stay on this machine, so they're lost with it. Coresmith can copy each one to an S3 bucket, without
            its keys.
        </p>
    {/if}
    {#if canManage}
        <div class="mt-3 flex flex-wrap gap-2">
            {#if copy}
                <Button variant="ghost" size="sm" onclick={edit}>Change copy</Button>
                <Button variant="ghost" size="sm" onclick={() => (stopping = true)}>Stop copying</Button>
            {:else}
                <Button variant="outline" size="sm" onclick={edit}>Copy to S3</Button>
            {/if}
        </div>
    {/if}
</div>

<Dialog.Root bind:open={editing}>
    <Dialog.Content class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
        <Form
            method="put"
            action={route('settings.backups.copy.update')}
            options={{ preserveScroll: true }}
            onSuccess={() => (editing = false)}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Copy backups to S3</Dialog.Title>
                    <Dialog.Description
                        >Each backup goes to the bucket without Coresmith's own keys. Use a bucket and an access key
                        that can only write to it. The access key is sealed and never shown again.</Dialog.Description
                    >
                </Dialog.Header>
                <div class="mt-4 flex flex-col gap-3">
                    <S3DestinationFields id="backup-copy" destination={copy} {errors} bind:pathStyle />
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={() => (editing = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing}>Save</Button>
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>

<Dialog.Root bind:open={stopping}>
    <Dialog.Content>
        <Dialog.Header>
            <Dialog.Title>Stop copying backups to S3?</Dialog.Title>
            <Dialog.Description
                >Copies already in the bucket stay there. New backups stay on this machine only.</Dialog.Description
            >
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" type="button" onclick={() => (stopping = false)}>Cancel</Button>
            <Button variant="destructive" disabled={working} onclick={stop}
                >{working ? 'Stopping…' : 'Stop copying'}</Button
            >
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
