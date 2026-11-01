<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { CloudCatalog, CloudPicks, CloudSize } from '@/lib/cloud'

    let {
        catalog,
        environments,
        errors,
        picks = $bindable({})
    }: {
        catalog: CloudCatalog
        environments: string[]
        errors: Record<string, string | undefined>
        picks?: CloudPicks
    } = $props()

    function settle(offered: string[], pick: string | undefined, fallback: string | null): string {
        if (pick !== undefined) {
            return offered.includes(pick) ? pick : (offered[0] ?? '')
        }

        return fallback !== null && offered.includes(fallback) ? fallback : (offered[0] ?? '')
    }

    const region = $derived(
        settle(
            catalog.regions.map((option) => option.id),
            picks.region,
            catalog.defaults.region
        )
    )
    const sizes = $derived(catalog.sizes.filter((option) => option.regions.includes(region)))
    const size = $derived(
        settle(
            sizes.map((option) => option.id),
            picks.size,
            catalog.defaults.size
        )
    )
    const chosen = $derived(sizes.find((option) => option.id === size) ?? null)
    const images = $derived(
        chosen === null ? [] : catalog.images.filter((option) => option.architectures.includes(chosen.architecture))
    )
    const image = $derived(
        settle(
            images.map((option) => option.id),
            picks.image,
            catalog.defaults.image
        )
    )
    const price = $derived(chosen === null ? null : (chosen.prices[region] ?? chosen.price))

    function priced(option: CloudSize): string {
        const amount = option.prices[region] ?? option.price

        return amount === null ? option.label : `${option.label} · ${amount}/mo`
    }

    function pick(key: keyof CloudPicks, event: Event) {
        picks[key] = (event.currentTarget as HTMLSelectElement).value
    }
</script>

<div class="grid grid-cols-2 gap-3">
    <div>
        <Label for="region" class="mb-1.5">Region</Label>
        <NativeSelect
            id="region"
            name="region"
            options={catalog.regions.map((option) => ({ value: option.id, label: option.label }))}
            value={region}
            onchange={(event) => pick('region', event)}
            aria-invalid={errors.region ? true : undefined}
        />
        <FieldError message={errors.region} />
    </div>
    <div>
        <Label for="size" class="mb-1.5">Size</Label>
        <NativeSelect
            id="size"
            name="size"
            options={sizes.map((option) => ({ value: option.id, label: priced(option) }))}
            value={size}
            onchange={(event) => pick('size', event)}
            aria-invalid={errors.size ? true : undefined}
        />
        <FieldError message={errors.size} />
    </div>
</div>
<div class="grid grid-cols-2 gap-3">
    <div>
        <Label for="image" class="mb-1.5">Image</Label>
        <NativeSelect
            id="image"
            name="image"
            options={images.map((option) => ({ value: option.id, label: option.label }))}
            value={image}
            onchange={(event) => pick('image', event)}
            aria-invalid={errors.image ? true : undefined}
        />
        <FieldError message={errors.image} />
    </div>
    <div>
        <Label for="environment" class="mb-1.5">Environment</Label>
        <NativeSelect
            id="environment"
            name="environment"
            options={environments}
            value={picks.environment ?? 'Production'}
            onchange={(event) => pick('environment', event)}
        />
        <FieldError message={errors.environment} />
    </div>
</div>
<div class="-mt-1 flex flex-col gap-1 text-[12px] text-muted-foreground">
    {#if chosen}
        <p class="m-0">
            {chosen.id} · {chosen.disk_gb} GB disk{price === null ? '' : ` · ${price} a month before tax`}, billed by {catalog.provider}.
        </p>
    {/if}
    <p class="m-0">Coresmith puts its own SSH key on the server. Password login is turned off during hardening.</p>
</div>
