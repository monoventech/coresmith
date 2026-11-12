<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { NativeSelect } from '@/components/ui/native-select'
    import { page, router } from '@inertiajs/svelte'

    let {
        scopes = [],
        scope = '',
        href = undefined,
        keep = {},
        class: className
    }: {
        scopes: { value: string; label: string }[]
        scope: string
        href?: string
        keep?: Record<string, string>
        class?: string
    } = $props()

    function go(event: Event) {
        const value = (event.currentTarget as HTMLSelectElement).value
        const [project, environment] = value.split(':')

        router.get(
            href ?? page.url.split('?')[0],
            { ...keep, ...(project ? { project } : {}), ...(environment ? { environment } : {}) },
            { preserveScroll: true }
        )
    }
</script>

{#if scopes.length > 0}
    <span class={className}>
        <NativeSelect value={scope} options={scopes} aria-label="Project" onchange={go} class="h-8 text-[12.5px]" />
    </span>
{/if}
