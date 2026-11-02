<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import PlanNote from '@/components/settings/PlanNote.svelte'
    import { Button } from '@/components/ui/button'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'

    const PERIODS = [
        { value: '30', label: 'Last 30 days' },
        { value: '90', label: 'Last 90 days' },
        { value: '365', label: 'Last year' },
        { value: 'all', label: 'Everything' }
    ]

    let { planNote = null }: { planNote?: string | null } = $props()

    let period = $state('90')
</script>

<div class="flex h-full flex-col rounded-xl border border-border bg-card px-5.5 py-5" data-testid="audit-log-card">
    <h3 class="type-h3 m-0 mb-1">Audit log</h3>
    <p class="mb-3 text-[12.5px] text-muted-foreground">
        Every change to this team's servers, apps and settings, with who made it and when. SSH commands are included for
        servers that record them.
    </p>
    {#if planNote}
        <PlanNote note={planNote} />
    {:else}
        <div class="mt-auto flex flex-wrap items-end gap-2.5">
            <div class="w-40">
                <Label for="audit-log-period" class="mb-1.5">Period</Label>
                <NativeSelect id="audit-log-period" bind:value={period} options={PERIODS} />
            </div>
            <Button
                variant="outline"
                size="sm"
                class="max-sm:h-11"
                href={route('settings.audit-log', { period })}
                download>Export CSV</Button
            >
        </div>
    {/if}
</div>
