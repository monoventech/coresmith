<script lang="ts">
    import AuthLayout from '@/layouts/auth/AuthSplitLayout.svelte'
    import type { Snippet } from 'svelte'

    let {
        title = '',
        description = '',
        children
    }: {
        title?: string
        description?: string
        children?: Snippet
    } = $props()
</script>

<AuthLayout {title} {description}>
    {@render children?.()}
</AuthLayout>
