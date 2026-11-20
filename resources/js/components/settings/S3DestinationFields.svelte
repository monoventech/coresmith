<script module lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    export type S3Destination = {
        endpoint: string | null
        region: string
        bucket: string
        prefix: string | null
        path_style: boolean
    }
</script>

<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { Switch } from '@/components/ui/switch'

    let {
        id,
        destination = null,
        errors,
        pathStyle = $bindable(false)
    }: {
        id: string
        destination?: S3Destination | null
        errors: Partial<Record<string, string>>
        pathStyle?: boolean
    } = $props()
</script>

<div>
    <Label for="{id}-endpoint" class="mb-1.5">Endpoint</Label>
    <Input
        id="{id}-endpoint"
        name="endpoint"
        value={destination?.endpoint ?? ''}
        placeholder="Leave empty for AWS"
        autocomplete="off"
        spellcheck="false"
        class="font-mono text-[13px]"
        aria-invalid={errors.endpoint ? true : undefined}
    />
    <FieldError message={errors.endpoint} />
</div>
<div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
    <div>
        <Label for="{id}-region" class="mb-1.5">Region</Label>
        <Input
            id="{id}-region"
            name="region"
            value={destination?.region ?? ''}
            required
            placeholder="eu-central-1"
            autocomplete="off"
            spellcheck="false"
            class="font-mono text-[13px]"
            aria-invalid={errors.region ? true : undefined}
        />
        <FieldError message={errors.region} />
    </div>
    <div>
        <Label for="{id}-bucket" class="mb-1.5">Bucket</Label>
        <Input
            id="{id}-bucket"
            name="bucket"
            value={destination?.bucket ?? ''}
            required
            autocomplete="off"
            spellcheck="false"
            class="font-mono text-[13px]"
            aria-invalid={errors.bucket ? true : undefined}
        />
        <FieldError message={errors.bucket} />
    </div>
</div>
<div>
    <Label for="{id}-prefix" class="mb-1.5">Path prefix</Label>
    <Input
        id="{id}-prefix"
        name="prefix"
        value={destination?.prefix ?? ''}
        autocomplete="off"
        spellcheck="false"
        class="font-mono text-[13px]"
        aria-invalid={errors.prefix ? true : undefined}
    />
    <FieldError message={errors.prefix} />
</div>
<div>
    <Label for="{id}-access-key" class="mb-1.5">Access key ID</Label>
    <Input
        id="{id}-access-key"
        name="access_key_id"
        required={!destination}
        placeholder={destination ? 'Saved, never shown' : ''}
        autocomplete="off"
        spellcheck="false"
        class="font-mono text-[13px]"
        aria-invalid={errors.access_key_id ? true : undefined}
    />
    <FieldError message={errors.access_key_id} />
</div>
<div>
    <Label for="{id}-secret" class="mb-1.5">Secret access key</Label>
    <Input
        id="{id}-secret"
        name="secret_access_key"
        type="password"
        required={!destination}
        placeholder={destination ? 'Saved, never shown' : ''}
        autocomplete="new-password"
        class="font-mono text-[13px]"
        aria-invalid={errors.secret_access_key ? true : undefined}
    />
    <FieldError message={errors.secret_access_key} />
</div>
<div class="flex items-start justify-between gap-4">
    <div>
        <Label id="{id}-path-style-label" for="{id}-path-style">Path-style addressing</Label>
        <p id="{id}-path-style-description" class="m-0 mt-0.5 text-[12.5px] text-muted-foreground">
            Needed for MinIO and some other S3-compatible stores.
        </p>
    </div>
    <Switch
        id="{id}-path-style"
        bind:checked={pathStyle}
        aria-labelledby="{id}-path-style-label"
        aria-describedby="{id}-path-style-description"
    />
    <input type="hidden" name="path_style" value={pathStyle ? '1' : '0'} />
</div>
