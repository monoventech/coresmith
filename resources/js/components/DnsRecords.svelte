<script lang="ts">
    import type { DnsRow } from '@/lib/dns'

    let { records, testid = 'dns-records' }: { records: DnsRow[]; testid?: string } = $props()

    let copied = $state<string | null>(null)

    async function copy(value: string) {
        try {
            await navigator.clipboard.writeText(value)
            copied = value
            setTimeout(() => (copied = null), 2000)
        } catch {
            copied = null
        }
    }
</script>

<div class="@container flex flex-col" data-testid={testid}>
    {#each records as record (`${record.type} ${record.value}`)}
        <div
            class="grid grid-cols-[40px_1fr] items-center gap-x-2.5 gap-y-1.5 border-t border-border py-2 @xs:grid-cols-[52px_1fr_auto]"
        >
            <span class="font-mono text-[11.5px] text-muted-foreground">{record.type}</span>
            <span class="min-w-0">
                {#if record.name}
                    <span class="block font-mono text-[12px] wrap-anywhere">{record.name}</span>
                {/if}
                <span class="block font-mono text-[12px] wrap-anywhere text-muted-foreground">{record.value}</span>
                {#if record.closed}
                    <span class="block text-[11.5px] text-warning-strong"
                        >The internet can't reach this address on ports 80 and 443 yet.</span
                    >
                {/if}
            </span>
            <button
                type="button"
                class="focus-ring quick-action col-start-2 h-8! justify-self-start px-3! text-[12.5px]! @xs:col-start-auto"
                aria-label="{copied === record.value ? 'Copied' : 'Copy'} the {record.type} value {record.value}"
                onclick={() => copy(record.value)}
            >
                {copied === record.value ? 'Copied' : 'Copy'}
            </button>
        </div>
    {/each}
</div>
