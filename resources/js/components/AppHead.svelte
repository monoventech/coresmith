<script lang="ts">
    import { page } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'

    let {
        title = '',
        children
    }: {
        title?: string
        children?: Snippet
    } = $props()

    function words(text: string): string[] {
        return text.toLocaleLowerCase().split(/\s+/).filter(Boolean)
    }

    function names(text: string, name: string): boolean {
        const said = words(text)
        const named = words(name)

        return named.length > 0 && said.some((_, start) => named.every((word, offset) => said[start + offset] === word))
    }

    const appName = $derived(page.props.appName || import.meta.env.VITE_APP_NAME || 'Coresmith')
    const fullTitle = $derived.by(() => {
        if (!title) {
            return appName
        }

        return names(title, appName) ? title : `${title} · ${appName}`
    })
</script>

<svelte:head>
    <title>{fullTitle}</title>
    {@render children?.()}
</svelte:head>
