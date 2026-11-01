<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import PhaseIcon from '@/components/PhaseIcon.svelte'
    import type { CloudStep } from '@/lib/cloud'
    import { cn } from '@/lib/utils'

    let { steps }: { steps: CloudStep[] } = $props()
</script>

<div class="flex flex-col gap-0.5">
    {#each steps as step (step.key)}
        <div class={cn('flex items-center gap-3 py-1.5', step.state === 'pending' && 'text-muted-foreground')}>
            <PhaseIcon state={step.state} />
            <span class="text-[13.5px]"
                ><span class="font-medium">{step.name}</span><span class="text-muted-foreground">, {step.desc}</span
                ></span
            >
        </div>
    {/each}
</div>
