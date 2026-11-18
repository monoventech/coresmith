<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import { TriangleAlert } from '@lucide/svelte'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { ExpectedDatabase } from '@/lib/databases'

    let { banner, href }: { banner: ExpectedDatabase; href: string } = $props()

    let working = $state(false)
    let picked = $state('')

    function use(value: string) {
        working = true
        router.post(href, { [banner.field]: value }, { preserveScroll: true, onFinish: () => (working = false) })
    }
</script>

<div
    class="tint-warning mb-4 flex flex-wrap items-center gap-3 rounded-xl border px-4.5 py-3 max-sm:mb-3 max-sm:items-start max-sm:px-3.5"
    data-testid="expected-database-banner"
>
    <TriangleAlert class="size-4.5 shrink-0 text-warning max-sm:size-4" />
    <span class="min-w-0 flex-1 text-[13.5px] max-sm:basis-[calc(100%-1.75rem)] max-sm:text-[12.5px]">
        <span class="font-semibold">{banner.lead}</span>
        {banner.detail}
    </span>
    {#if banner.create || banner.existing.length > 0}
        <div class="flex flex-wrap items-center gap-2 max-sm:ml-7 max-sm:w-[calc(100%-1.75rem)]">
            {#if banner.create}
                <button
                    type="button"
                    class="focus-ring quick-action h-8! px-3! text-[12.5px]! disabled:cursor-not-allowed disabled:opacity-50 max-sm:h-auto! max-sm:min-h-11 max-sm:py-2! max-sm:text-left"
                    disabled={working}
                    onclick={() => banner.create && use(banner.create.value)}>{banner.create.label}</button
                >
            {/if}
            {#if banner.existing.length === 1}
                <button
                    type="button"
                    class="focus-ring quick-action h-8! px-3! text-[12.5px]! disabled:cursor-not-allowed disabled:opacity-50 max-sm:h-11!"
                    disabled={working}
                    onclick={() => use(banner.existing[0].value)}>Use {banner.existing[0].label}</button
                >
            {:else if banner.existing.length > 1}
                <div class="flex items-center gap-2 max-sm:w-full">
                    <NativeSelect
                        aria-label="A database already on this server"
                        class="h-8 text-[12.5px] max-sm:h-11"
                        placeholder="Pick one"
                        bind:value={picked}
                        options={banner.existing}
                    />
                    <button
                        type="button"
                        class="focus-ring quick-action h-8! shrink-0 px-3! text-[12.5px]! disabled:cursor-not-allowed disabled:opacity-50 max-sm:h-11!"
                        disabled={working || picked === ''}
                        onclick={() => use(picked)}>Use it</button
                    >
                </div>
            {/if}
        </div>
    {/if}
</div>
