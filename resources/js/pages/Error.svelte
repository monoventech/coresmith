<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { setLayoutProps } from '@inertiajs/svelte'
    import AppHead from '@/components/AppHead.svelte'
    import { Button } from '@/components/ui/button'

    type Copy = { head: string; title: string; description: string }

    let { status, expired = false }: { status: 403 | 404 | 500; expired?: boolean } = $props()

    const PAGES: Record<403 | 404 | 500, Copy> = {
        403: {
            head: 'No access',
            title: 'No access to this page',
            description: "Your role doesn't cover it. Ask one of your team's owners or admins if you need it."
        },
        404: {
            head: 'Page not found',
            title: 'Page not found',
            description:
                'Check the address for a typo. If a link in Coresmith brought you here, what it pointed to may have been removed.'
        },
        500: {
            head: "Page didn't load",
            title: "This page didn't load",
            description: 'Coresmith ran into an error. Try again in a moment.'
        }
    }

    const EXPIRED: Copy = {
        head: 'Link expired',
        title: 'This link has expired',
        description: 'It may be too old, or it got cut short when it was copied. Ask for a new one.'
    }

    const copy = $derived(expired ? EXPIRED : PAGES[status])

    $effect(() => {
        setLayoutProps({ title: copy.title, description: copy.description })
    })
</script>

<AppHead title={copy.head} />

<p class="mb-4 font-mono text-[12.5px] text-muted-foreground" data-testid="error-status">Error {status}</p>

{#if status === 500}
    <Button onclick={() => window.location.reload()}>Try again</Button>
{:else}
    <Button href={route('home')}>Go to your fleet</Button>
{/if}
