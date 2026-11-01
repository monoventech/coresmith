<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { CloudProviderType } from '@/lib/cloud'
    import { Form } from '@inertiajs/svelte'
    import { OctagonAlert } from '@lucide/svelte'

    type Choice = { id: string; label: string; monthly: string | null }
    type Choices = { current: Choice & { disk_gb: number }; sizes: Choice[]; note: string; error: null }

    let {
        server,
        provider,
        type,
        open = $bindable(false)
    }: { server: { id: string; name: string }; provider: string; type: CloudProviderType; open?: boolean } = $props()

    let choices = $state<Choices | null>(null)
    let failure = $state<string | null>(null)
    let size = $state('')

    const cycles = $derived(type === 'hetzner' || type === 'digitalocean')
    const options = $derived(
        (choices?.sizes ?? []).map((choice) => ({
            value: choice.id,
            label: [choice.label, choice.monthly ? `${choice.monthly}/mo` : null, choice.id].filter(Boolean).join(' · ')
        }))
    )

    $effect(() => {
        if (!open) {
            return
        }

        let cancelled = false

        choices = null
        failure = null

        fetch(route('servers.cloud.sizes', server.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then((response) => response.json())
            .then((body: Choices | { error: string }) => {
                if (cancelled) {
                    return
                }

                if (body.error !== null) {
                    failure = body.error
                } else {
                    choices = body
                    size = body.sizes[0]?.id ?? ''
                }
            })
            .catch(() => {
                if (!cancelled) {
                    failure = `Coresmith couldn't load the sizes from ${provider}. Try again in a minute.`
                }
            })

        return () => {
            cancelled = true
        }
    })

    function close() {
        open = false
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content>
        <Form
            method="post"
            action={route('servers.cloud.resize', server.id)}
            options={{ preserveScroll: true }}
            onSuccess={close}
        >
            {#snippet children({ errors, processing })}
                <Dialog.Header>
                    <Dialog.Title>Resize {server.name}</Dialog.Title>
                    <Dialog.Description
                        >{cycles
                            ? `${server.name} shuts down, ${provider} moves it to the new size, and it starts again. Apps are offline for a few minutes.`
                            : `${provider} moves ${server.name} to the new size and starts it again. Apps are offline for a few minutes.`}</Dialog.Description
                    >
                </Dialog.Header>

                <div class="mt-4">
                    {#if failure}
                        <div
                            class="tint-destructive flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 text-[12.5px]"
                            role="alert"
                        >
                            <OctagonAlert class="mt-0.5 size-4 shrink-0 text-destructive" />
                            <span>{failure}</span>
                        </div>
                    {:else if choices}
                        <Label for="resize-size" class="mb-1.5">New size</Label>
                        <NativeSelect
                            id="resize-size"
                            name="size"
                            bind:value={size}
                            {options}
                            aria-invalid={errors.size ? true : undefined}
                        />
                        <p class="mt-1.5 mb-0 text-[12.5px] text-muted-foreground">{choices.note}</p>
                        <FieldError message={errors.size} />
                    {:else}
                        <p class="m-0 text-[12.5px] text-muted-foreground" role="status">
                            Loading sizes from {provider}.
                        </p>
                    {/if}
                </div>

                <Dialog.Footer>
                    {#if choices}
                        <Button variant="ghost" type="button" onclick={close}>Keep {choices.current.id}</Button>
                        <Button type="submit" disabled={processing || size === ''}>Resize to {size}</Button>
                    {:else}
                        <Button variant="ghost" type="button" onclick={close}>Close</Button>
                    {/if}
                </Dialog.Footer>
            {/snippet}
        </Form>
    </Dialog.Content>
</Dialog.Root>
