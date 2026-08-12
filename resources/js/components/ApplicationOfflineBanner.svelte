<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    let { stopped = false, maintenance = false }: { stopped?: boolean; maintenance?: boolean } = $props()
</script>

{#if stopped || maintenance}
    <p
        class="mb-6 rounded-xl border border-warning/40 bg-warning/10 p-4 text-sm text-warning-strong"
        role="status"
        data-testid="offline-banner"
    >
        {#if stopped}
            This application is stopped. Visitors see a maintenance page instead of the site.
        {:else}
            Maintenance mode is on. Visitors see a maintenance page, but the processes keep running so you can still
            debug.
        {/if}
    </p>
{/if}
