<script lang="ts">
    import { Link } from '@inertiajs/svelte'

    let { href, label }: { href: string; label: string } = $props()
</script>

<Link
    {href}
    class="focus-ring mb-3.5 inline-flex items-center gap-1.5 rounded-sm bg-transparent py-1 text-[13px] font-medium text-muted-foreground no-underline sm:hidden"
    data-testid="back-link"
>
    ← {label}
</Link>
