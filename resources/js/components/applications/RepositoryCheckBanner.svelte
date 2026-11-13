<script lang="ts">
    import { Button } from '@/components/ui/button'
    import type { RepositoryCheck } from '@/lib/detection'
    import { router } from '@inertiajs/svelte'
    import { TriangleAlert } from '@lucide/svelte'

    let { check, canDeploy }: { check: RepositoryCheck; canDeploy: boolean } = $props()

    let checking = $state(false)
    let applying = $state(false)

    const outcome = $derived(check.result?.state ?? null)
    const heading = $derived(
        outcome === 'failed'
            ? "Coresmith couldn't read this repository."
            : outcome === 'blocked'
              ? "This repository can't deploy as is."
              : outcome === 'proposal'
                ? "This app's stack can't run this repository."
                : "Coresmith hasn't read this repository yet, so the first deploy waits for a check."
    )

    function run() {
        checking = true
        router.post(check.check_href, {}, { preserveScroll: true, onFinish: () => (checking = false) })
    }

    function use() {
        applying = true
        router.put(check.apply_href, {}, { preserveScroll: true, onFinish: () => (applying = false) })
    }
</script>

<div
    class="tint-warning mb-4 flex flex-wrap items-start gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:px-3.5"
    data-testid="repository-check-banner"
>
    <TriangleAlert class="mt-0.5 size-4.5 shrink-0 text-warning max-sm:size-4" />
    <div
        class="flex min-w-0 flex-1 flex-col gap-1.5 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]"
    >
        <span id="repository-check-heading" class="font-semibold">{heading}</span>
        {#if outcome === 'failed'}
            <span class="wrap-anywhere">{check.result?.failure}</span>
        {:else if outcome === 'blocked' || outcome === 'proposal'}
            {#each check.result?.blockers ?? [] as blocker (blocker.reason)}
                <span class="wrap-anywhere"
                    >{blocker.reason}{#if blocker.fix}<span class="font-medium">{` ${blocker.fix}`}</span>{/if}</span
                >
            {/each}
            {#if outcome === 'proposal' && check.result?.proposal}
                <span>{`Coresmith read ${check.result.proposal.label} from the repository.`}</span>
            {/if}
        {:else if check.access === 'key'}
            <span>Add the deploy key shown under Source to the repository, then check it.</span>
        {:else}
            <span>Check it to see the stack it runs on before anything deploys.</span>
        {/if}
    </div>
    {#if canDeploy}
        <div class="flex flex-wrap gap-2 max-sm:w-full">
            {#if outcome === 'proposal' && check.result?.proposal}
                <Button size="sm" disabled={applying} onclick={use} class="max-sm:min-h-11 max-sm:flex-1"
                    >{`Use ${check.result.proposal.label}`}</Button
                >
            {/if}
            <Button
                size="sm"
                variant={outcome === 'proposal' ? 'outline' : 'default'}
                disabled={checking}
                onclick={run}
                class="max-sm:min-h-11 max-sm:flex-1"
                >{outcome === null ? 'Check the repository' : 'Check again'}</Button
            >
        </div>
    {/if}
</div>
