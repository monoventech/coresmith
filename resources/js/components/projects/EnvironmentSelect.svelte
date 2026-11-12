<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import FieldError from '@/components/FieldError.svelte'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'

    type Choice = { id: string; name: string; environments: { id: string; name: string }[] }

    let { projects = [], error }: { projects: Choice[]; error?: string | null } = $props()
</script>

{#if projects.length > 0}
    <div>
        <Label for="environment_id" class="mb-1.5">Project</Label>
        <NativeSelect id="environment_id" name="environment_id" value="" aria-invalid={error ? true : undefined}>
            <option value="">No project</option>
            {#each projects as project (project.id)}
                <optgroup label={project.name}>
                    {#each project.environments as environment (environment.id)}
                        <option value={environment.id}>{project.name} › {environment.name}</option>
                    {/each}
                </optgroup>
            {/each}
        </NativeSelect>
        <FieldError message={error} />
    </div>
{/if}
