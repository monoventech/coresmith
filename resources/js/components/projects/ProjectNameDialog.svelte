<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { router } from '@inertiajs/svelte'
    import { untrack } from 'svelte'

    let {
        open = $bindable(false),
        title,
        description,
        label,
        submit,
        href,
        method = 'post',
        initial = '',
        maxlength
    }: {
        open: boolean
        title: string
        description: string
        label: string
        submit: string
        href: string
        method?: 'post' | 'put'
        initial?: string
        maxlength: number
    } = $props()

    const id = $props.id()

    let name = $state('')
    let error = $state<string | undefined>()
    let working = $state(false)

    $effect(() => {
        if (open) {
            untrack(() => {
                name = initial
                error = undefined
            })
        }
    })

    function save(event: SubmitEvent) {
        event.preventDefault()
        working = true
        router[method](
            href,
            { name },
            {
                preserveScroll: true,
                onSuccess: () => (open = false),
                onError: (errors: Record<string, string>) => (error = errors.name),
                onFinish: () => (working = false)
            }
        )
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content>
        <form onsubmit={save} novalidate>
            <Dialog.Header>
                <Dialog.Title>{title}</Dialog.Title>
                <Dialog.Description>{description}</Dialog.Description>
            </Dialog.Header>
            <div class="mt-4">
                <Label for="{id}-name" class="mb-1.5">{label}</Label>
                <Input
                    id="{id}-name"
                    bind:value={name}
                    required
                    {maxlength}
                    autocomplete="off"
                    aria-invalid={error ? true : undefined}
                />
                <FieldError message={error} />
            </div>
            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                <Button type="submit" disabled={working}>{submit}</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
