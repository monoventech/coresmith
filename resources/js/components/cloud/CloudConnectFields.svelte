<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import type { CloudTypeOption } from '@/lib/cloud'

    let {
        type,
        error = null,
        id = 'cloud-api-token'
    }: { type: CloudTypeOption; error?: string | null; id?: string } = $props()
</script>

<div>
    <Label for={id} class="mb-1.5">API token</Label>
    <Input
        {id}
        name="api_token"
        type="password"
        autocomplete="off"
        spellcheck="false"
        required
        aria-invalid={error ? true : undefined}
        aria-describedby="{id}-hint"
    />
    <FieldError message={error} />
    <p id="{id}-hint" class="mt-2 text-[12px] text-muted-foreground">
        Stored encrypted. Used only to create, resize, reboot and destroy servers.
    </p>
    <p class="mt-2 text-[12px] text-muted-foreground">
        {type.help}
        <a
            class="focus-ring rounded-sm font-medium text-primary-strong underline-offset-2 hover:underline"
            href={type.token_url}
            target="_blank"
            rel="noopener noreferrer">Open {type.label} token settings</a
        >
    </p>
</div>
