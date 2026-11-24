<script module lang="ts">
    import type { StateTone } from '@/lib/fleet'

    export type AddressRow = { label: string; values: string[]; note: string | null; tone: StateTone | null }
    export type Addresses = { rows: AddressRow[]; checked: string | null }
</script>

<script lang="ts">
    import StateDot from '@/components/StateDot.svelte'

    let { addresses }: { addresses: Addresses } = $props()
</script>

<section
    class="mb-4 flex flex-col rounded-xl border border-border bg-card px-5.5 pt-5 pb-4 max-sm:px-4"
    aria-labelledby="addresses-title"
    data-testid="addresses-card"
>
    <h2 id="addresses-title" class="type-h3 m-0">Addresses</h2>
    <p class="m-0 mt-1 text-[13px] text-muted-foreground">Where visitors and your other servers reach this server.</p>
    <dl class="m-0 mt-3 flex flex-col">
        {#each addresses.rows as row (row.label)}
            <div
                class="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-3 border-t border-border py-2.5 max-sm:grid-cols-[4.5rem_minmax(0,1fr)]"
                data-testid="address-row"
            >
                <dt class="text-[13px] font-medium text-muted-foreground">{row.label}</dt>
                <dd class="m-0 flex min-w-0 flex-col gap-1">
                    {#each row.values as value (value)}
                        <span class="font-mono text-[13px] wrap-anywhere">{value}</span>
                    {:else}
                        <span class="text-[13px] text-muted-foreground">None</span>
                    {/each}
                    {#if row.note}
                        <span class="flex items-start gap-1.5 text-[12.5px] text-muted-foreground">
                            {#if row.tone}<StateDot tone={row.tone} size="sm" class="mt-1.5" />{/if}{row.note}
                        </span>
                    {/if}
                </dd>
            </div>
        {/each}
    </dl>
    <p class="m-0 border-t border-border pt-3 text-[12.5px] text-muted-foreground">
        {addresses.checked ? `Checked ${addresses.checked}.` : 'Not checked yet.'}
    </p>
</section>
