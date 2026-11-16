<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'

    let {
        projects = [],
        value = null,
        error
    }: { projects: { id: string; name: string }[]; value?: string | null; error?: string | null } = $props()
</script>

{#if projects.length > 0}
    <div>
        <Label for="project_id" class="mb-1.5">Project</Label>
        <NativeSelect id="project_id" name="project_id" value={value ?? ''} aria-invalid={error ? true : undefined}>
            <option value="">No project</option>
            {#each projects as project (project.id)}
                <option value={project.id}>{project.name}</option>
            {/each}
        </NativeSelect>
        <FieldError message={error} />
    </div>
{/if}
