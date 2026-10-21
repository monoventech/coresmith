<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import * as RadioGroup from '@/components/ui/radio-group'
    import type { GitHubChoice } from '@/lib/github'
    import { cn } from '@/lib/utils'
    import { router } from '@inertiajs/svelte'

    let { choose }: { choose: GitHubChoice } = $props()

    let open = $state(true)
    let selected = $state('')
    let working = $state(false)

    const linkable = $derived(choose.installations.filter((installation) => installation.refusal === null))

    function connect() {
        working = true
        router.post(
            choose.post_href,
            { installation_id: selected },
            { preserveScroll: true, onFinish: () => (working = false) }
        )
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content data-testid="github-chooser">
        <Dialog.Header>
            <Dialog.Title>Choose the account to connect</Dialog.Title>
            <Dialog.Description
                >Your GitHub account can reach more than one installation of {choose.app}.</Dialog.Description
            >
        </Dialog.Header>
        <RadioGroup.Root
            bind:value={selected}
            aria-label="Installations"
            class="mt-4 gap-0 overflow-hidden rounded-lg border border-border"
        >
            {#each choose.installations as installation (installation.id)}
                <label
                    class={cn(
                        'flex items-center gap-3 border-b border-border px-3.5 py-2.5 last:border-b-0',
                        installation.refusal === null ? 'cursor-pointer hover:bg-muted' : 'cursor-not-allowed'
                    )}
                >
                    <RadioGroup.Item
                        value={installation.id}
                        disabled={installation.refusal !== null}
                        aria-labelledby="github-installation-{installation.id}"
                        aria-describedby={installation.refusal === null
                            ? undefined
                            : `github-installation-${installation.id}-refusal`}
                    />
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span id="github-installation-{installation.id}" class="text-[13.5px]">
                            <span class="font-mono text-[13px]">{installation.account}</span>
                            · {installation.repository_selection === 'all' ? 'all repositories' : 'chosen repositories'}
                        </span>
                        {#if installation.refusal !== null}
                            <span
                                id="github-installation-{installation.id}-refusal"
                                class="text-[12px] text-muted-foreground">{installation.refusal}</span
                            >
                        {/if}
                    </span>
                </label>
            {/each}
        </RadioGroup.Root>
        <Dialog.Footer>
            <Button variant="ghost" onclick={() => (open = false)}>Cancel</Button>
            {#if linkable.length > 0}
                <Button disabled={selected === '' || working} onclick={connect}>Connect</Button>
            {/if}
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
