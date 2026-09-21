<script lang="ts">
    import { CircleAlert } from '@lucide/svelte'

    let { message }: { message?: string | null } = $props()
</script>

{#if message}
    <p class="mt-2 flex items-center gap-2 text-[12.5px] text-destructive" role="alert">
        <CircleAlert class="size-3.75 shrink-0" />
        {message}
    </p>
{/if}
