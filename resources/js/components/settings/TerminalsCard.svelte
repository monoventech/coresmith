<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import ToggleRow from '@/components/ToggleRow.svelte'

    let { root, canChange }: { root: boolean; canChange: boolean } = $props()

    let busy = $state(false)

    const description = $derived(
        (root
            ? 'Owners can open a root shell, with full control of a server.'
            : 'Nobody on the team can open a root shell, owners included.') +
            (canChange ? '' : ' Only owners can change this.')
    )

    function change(on: boolean) {
        busy = true
        router.put(
            route('settings.root-terminals.update'),
            { root_terminals: on },
            { preserveScroll: true, onFinish: () => (busy = false) }
        )
    }
</script>

<section
    class="rounded-xl border border-border bg-card px-5.5 pt-5 pb-1.5 max-sm:px-4 max-sm:pt-4"
    aria-labelledby="terminals-heading"
    data-testid="terminals-card"
>
    <h3 id="terminals-heading" class="type-h3 m-0">Terminals</h3>
    <p class="m-0 mt-1 mb-3 text-[13px] text-muted-foreground">
        Owners, and developers on servers they added, can open a shell as an app's user. A shell ends on its own after
        10 minutes without typing, or after an hour.
    </p>
    <ToggleRow
        label="Root terminals"
        {description}
        checked={root}
        {busy}
        disabled={!canChange}
        onchange={change}
        testid="root-terminals"
    />
</section>
