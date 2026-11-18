<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import CloudProvidersCard from '@/components/settings/CloudProvidersCard.svelte'
    import type { CloudAccounts } from '@/lib/cloud'

    let { cloud }: { cloud: CloudAccounts } = $props()
</script>

<AppHead title="Cloud providers" />

<CloudProvidersCard {cloud} />
