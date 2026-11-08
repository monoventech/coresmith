<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Form } from '@inertiajs/svelte'

    let {
        server,
        provider,
        uncertain = false,
        open = $bindable(false),
        ondetach
    }: {
        server: { id: string; name: string }
        provider: string
        uncertain?: boolean
        open?: boolean
        ondetach: () => void
    } = $props()

    let typed = $state('')

    function close() {
        typed = ''
        open = false
    }

    function detachInstead() {
        close()
        ondetach()
    }

    function changed(value: boolean) {
        if (!value) {
            typed = ''
        }
    }
</script>

<Dialog.Root bind:open onOpenChange={changed}>
    <Dialog.Content>
        <Form
            method="delete"
            action={route('servers.cloud.destroy', server.id)}
            options={{ preserveScroll: true }}
            onSuccess={close}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Destroy {server.name}?</Dialog.Title>
                    <Dialog.Description
                        >{uncertain
                            ? `Coresmith could not confirm whether ${provider} created ${server.name}. If it did, ${provider} deletes it and its disk, and billing for it stops. Either way, ${server.name} leaves Coresmith.`
                            : `${provider} deletes the server and its disk. Everything on it is gone and can't be recovered, and billing for it stops.`}</Dialog.Description
                    >
                </Dialog.Header>
                <p class="mt-3 mb-0 text-[12.5px] text-muted-foreground">
                    To keep the server and only stop managing it here, <button
                        type="button"
                        class="focus-ring cursor-pointer rounded-sm bg-transparent p-0 font-medium text-primary-strong underline-offset-2 hover:underline"
                        onclick={detachInstead}>detach it instead</button
                    >.
                </p>
                <div class="mt-4">
                    <Label for="destroy-confirm" class="mb-1.5">Type {server.name} to confirm</Label>
                    <Input
                        id="destroy-confirm"
                        name="confirm"
                        autocomplete="off"
                        bind:value={typed}
                        aria-invalid={errors.confirm ? true : undefined}
                    />
                    <FieldError message={errors.confirm} />
                </div>
                <Dialog.Footer>
                    <Button variant="ghost" type="button" onclick={close}>Keep it</Button>
                    <Button variant="destructive" type="submit" disabled={processing || typed !== server.name}
                        >Destroy server</Button
                    >
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>
