<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { TAG_LIMIT, cleanTag, sameTag, tagProblem } from '@/lib/tags'
    import { Plus, X } from '@lucide/svelte'

    let {
        id,
        tags = $bindable([]),
        suggestions = [],
        suggestionsLabel = 'Used on other apps',
        name,
        error = null,
        describedby,
        labelledby,
        onchange
    }: {
        id: string
        labelledby?: string
        tags?: string[]
        suggestions?: string[]
        suggestionsLabel?: string
        name?: string
        error?: string | null
        describedby?: string
        onchange?: (tags: string[]) => void
    } = $props()

    let text = $state('')
    let problem = $state<string | null>(null)
    let input = $state<HTMLInputElement | null>(null)

    const needle = $derived(cleanTag(text).toLocaleLowerCase())
    const offered = $derived(
        suggestions
            .filter((each) => !tags.some((tag) => sameTag(tag, each)))
            .filter((each) => needle === '' || each.toLocaleLowerCase().includes(needle))
            .slice(0, 8)
    )
    const message = $derived(problem ?? error)
    const full = $derived(tags.length >= TAG_LIMIT)

    function add(value: string): boolean {
        const tag = cleanTag(value)

        if (tag === '' || tags.some((each) => sameTag(each, tag))) {
            text = ''
            problem = null

            return false
        }

        const issue = tagProblem(tag, tags.length)

        if (issue !== null) {
            problem = issue

            return false
        }

        tags = [...tags, tag]
        text = ''
        problem = null
        onchange?.(tags)

        return true
    }

    function remove(tag: string) {
        tags = tags.filter((each) => each !== tag)
        problem = null
        onchange?.(tags)
        input?.focus()
    }

    export function flush() {
        if (cleanTag(text) !== '') {
            add(text)
        }
    }

    function onkeydown(event: KeyboardEvent) {
        if ((event.key === 'Enter' || event.key === ',') && cleanTag(text) !== '') {
            event.preventDefault()
            add(text)
        } else if (event.key === ',') {
            event.preventDefault()
        } else if (event.key === 'Backspace' && text === '' && tags.length > 0) {
            event.preventDefault()
            remove(tags[tags.length - 1])
        }
    }
</script>

<div>
    <div
        class="flex min-h-10 w-full flex-wrap items-center gap-1.5 rounded-md border border-input bg-background px-2 py-1.5 transition-[border-color] duration-(--dur-fast) ease-(--ease-out-soft) has-aria-invalid:border-destructive has-[input:focus-visible]:border-ring has-[input:focus-visible]:shadow-[0_0_0_2px_var(--background),0_0_0_4px_var(--ring)]"
        data-testid="tag-input"
    >
        {#each tags as tag (tag)}
            <span
                class="inline-flex h-6.5 max-w-full items-center rounded-sm border border-border bg-muted pl-2 text-[12.5px] text-foreground"
                data-testid="tag"
            >
                <span class="min-w-0 truncate">{tag}</span>
                <button
                    type="button"
                    class="focus-ring grid size-6 shrink-0 cursor-pointer place-items-center rounded-sm text-muted-foreground hover:text-foreground"
                    aria-label="Remove {tag}"
                    onclick={() => remove(tag)}
                >
                    <X class="size-3" />
                </button>
                {#if name}
                    <input type="hidden" name="{name}[]" value={tag} />
                {/if}
            </span>
        {/each}
        <input
            {id}
            bind:this={input}
            bind:value={text}
            class="h-7 min-w-24 flex-1 border-0 bg-transparent px-1 font-sans text-[14px] text-foreground outline-none placeholder:text-muted-foreground"
            placeholder={full ? '' : tags.length === 0 ? 'production, client name…' : 'Add another'}
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            enterkeyhint="done"
            maxlength={64}
            aria-describedby={describedby}
            aria-labelledby={labelledby}
            aria-invalid={message ? true : undefined}
            oninput={() => (problem = null)}
            {onkeydown}
        />
        {#if name && cleanTag(text) !== ''}
            <input type="hidden" name="{name}[]" value={cleanTag(text)} />
        {/if}
    </div>
    <FieldError {message} />
    {#if offered.length > 0 && !full}
        <div class="mt-2 flex flex-wrap items-center gap-1.5" data-testid="tag-suggestions">
            <span class="text-[12px] text-muted-foreground">{suggestionsLabel}</span>
            {#each offered as suggestion (suggestion)}
                <button
                    type="button"
                    class="focus-ring inline-flex h-6.5 max-w-full cursor-pointer items-center gap-1 rounded-sm border border-dashed border-border bg-transparent px-2 text-[12.5px] text-muted-foreground hover:border-solid hover:text-foreground"
                    aria-label="Add {suggestion}"
                    onmousedown={(event) => event.preventDefault()}
                    onclick={() => add(suggestion) && input?.focus()}
                >
                    <Plus class="size-3 shrink-0" />
                    <span class="min-w-0 truncate">{suggestion}</span>
                </button>
            {/each}
        </div>
    {/if}
</div>
