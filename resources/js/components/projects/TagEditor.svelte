<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import AppTags from '@/components/projects/AppTags.svelte'
    import TagInput from '@/components/projects/TagInput.svelte'
    import * as Popover from '@/components/ui/popover'
    import { firstTagError } from '@/lib/tags'
    import { cn } from '@/lib/utils'
    import { router } from '@inertiajs/svelte'
    import { Pencil, Tag } from '@lucide/svelte'

    let {
        name,
        tags = [],
        href = null,
        suggestions = [],
        limit,
        class: className
    }: {
        name: string
        tags?: string[]
        href?: string | null
        suggestions?: string[]
        limit?: number
        class?: string
    } = $props()

    const uid = $props.id()

    let open = $state(false)
    let draft = $state<string[]>([])
    let error = $state<string | null>(null)
    let saving = $state(false)
    let editor = $state<ReturnType<typeof TagInput> | null>(null)
    let waiting: string[] | null = null

    const shown = $derived(open || saving ? draft : tags)

    function toggled(next: boolean) {
        if (next) {
            draft = [...tags]
            error = null
        } else {
            editor?.flush()
        }
    }

    function save(next: string[]) {
        waiting = [...next]

        if (!saving) {
            send()
        }
    }

    function send() {
        if (waiting === null || href === null) {
            return
        }

        const body = waiting

        waiting = null
        saving = true
        router.put(
            href,
            { tags: body },
            {
                async: true,
                preserveScroll: true,
                preserveState: true,
                onError: (errors: Record<string, string>) => {
                    waiting = null
                    error = firstTagError(errors)
                    draft = [...tags]
                },
                onFinish: () => {
                    saving = false
                    send()
                }
            }
        )
    }
</script>

{#if href === null}
    <AppTags {tags} {limit} class={className} />
{:else}
    <Popover.Root bind:open onOpenChange={toggled}>
        <Popover.Trigger>
            {#snippet child({ props })}
                <button
                    type="button"
                    {...props}
                    class={cn(
                        'focus-ring group/tags inline-flex min-h-6 max-w-full cursor-pointer items-center gap-1.5 rounded-sm bg-transparent p-0 text-left',
                        className
                    )}
                    aria-busy={saving}
                    data-testid="tag-editor"
                >
                    {#if shown.length > 0}
                        <span class="sr-only">Edit the tags on {name}:</span>
                        <AppTags tags={shown} {limit} />
                        <Pencil
                            class="size-3 shrink-0 text-muted-foreground opacity-0 transition-opacity duration-(--dur-fast) group-hover/tags:opacity-100 group-focus-visible/tags:opacity-100 max-sm:opacity-100"
                            aria-hidden="true"
                        />
                    {:else}
                        <span
                            class="inline-flex items-center gap-1 text-[12px] text-muted-foreground transition-colors duration-(--dur-fast) group-hover/tags:text-foreground"
                        >
                            <Tag class="size-3" aria-hidden="true" />
                            Add tags<span class="sr-only"> to {name}</span>
                        </span>
                    {/if}
                </button>
            {/snippet}
        </Popover.Trigger>
        <Popover.Content
            align="start"
            class="w-80 max-sm:w-[calc(100vw-2rem)]"
            data-testid="tag-editor-panel"
            onOpenAutoFocus={(event: Event) => {
                event.preventDefault()
                document.getElementById(`${uid}-input`)?.focus()
            }}
        >
            <Popover.Header>
                <Popover.Title id="{uid}-title" class="wrap-anywhere">Tags on {name}</Popover.Title>
                <Popover.Description id="{uid}-hint" class="text-[12.5px] text-muted-foreground"
                    >Changes save as you make them.</Popover.Description
                >
            </Popover.Header>
            <TagInput
                bind:this={editor}
                bind:tags={draft}
                id="{uid}-input"
                labelledby="{uid}-title"
                describedby="{uid}-hint"
                {suggestions}
                {error}
                onchange={save}
            />
        </Popover.Content>
    </Popover.Root>
{/if}
