<script lang="ts">
    import type { Snippet } from 'svelte'
    import PageTabs from '@/components/PageTabs.svelte'

    let { children }: { children?: Snippet } = $props()

    const tabs = [
        { label: 'Profile', href: route('profile.edit') },
        { label: 'Security', href: route('security.edit') }
    ]
</script>

<h1 class="type-h1 m-0">Your account</h1>
<p class="type-body-sm mt-1.5 text-muted-foreground">Your name, sign-in details and two-factor authentication. These follow you across every panel you belong to.</p>
<div class="mt-[22px] mb-9">
    <PageTabs {tabs} label="Account" />
</div>
{@render children?.()}
