<script lang="ts">
    import type { Detection } from '@/lib/detection'
    import { TriangleAlert } from '@lucide/svelte'

    let { detection, reading, server }: { detection: Detection | null; reading: boolean; server: string } = $props()

    const blocked = $derived(
        detection?.state === 'read' && detection.verdict === 'blocked'
            ? detection.blockers
            : detection?.state === 'failed' && detection.definitive
              ? [{ field: 'repository', reason: detection.failure, fix: '' }]
              : []
    )
</script>

<div data-testid="stack-detection" aria-live="polite">
    {#if reading}
        <p class="m-0 flex items-center gap-2 text-[12.5px] text-muted-foreground">
            <span class="status-dot-pulse size-1.75 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
            <span>Reading the repository on <span class="font-mono text-[12px]">{server}</span>…</span>
        </p>
    {:else if blocked.length > 0}
        <div class="tint-warning flex gap-3 rounded-lg border px-3.5 py-3">
            <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" />
            <div class="flex min-w-0 flex-col gap-1.5 text-[13px]">
                <span class="font-semibold">
                    {detection?.state === 'failed'
                        ? "Coresmith couldn't read this repository"
                        : "This repository can't deploy as is"}
                </span>
                {#each blocked as blocker (blocker.reason)}
                    <span class="wrap-anywhere"
                        >{blocker.reason}{#if blocker.fix}<span class="font-medium">{` ${blocker.fix}`}</span
                            >{/if}</span
                    >
                {/each}
            </div>
        </div>
    {:else if detection?.state === 'read'}
        <p class="m-0 text-[12.5px] text-muted-foreground">
            {#if detection.verdict === 'ready'}
                Filled in from the repository{#if detection.commit}&#32;at
                    <span class="font-mono text-[12px]">{detection.commit.slice(0, 7)}</span>{/if}. Check it, then add
                the app.
            {:else}
                Filled in what the repository settles. The fields marked below need you.
            {/if}
        </p>
    {:else if detection?.state === 'failed'}
        <p class="m-0 text-[12.5px] text-muted-foreground">
            {detection.failure} You can add the app now, and Coresmith checks the repository before the first deploy.
        </p>
    {:else if detection?.state === 'needs_key'}
        <p class="m-0 text-[12.5px] text-muted-foreground">
            Coresmith reads git@ repositories with this app's deploy key, which it makes when you add the app. Add the
            key to the repository, then check it on the app page before the first deploy.
        </p>
    {:else if detection?.state === 'server_not_ready'}
        <p class="m-0 text-[12.5px] text-muted-foreground">
            Coresmith reads the repository once {detection.server} is ready. You can add the app now and check it before the
            first deploy.
        </p>
    {/if}
</div>
