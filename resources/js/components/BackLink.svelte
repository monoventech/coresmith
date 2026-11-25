<script lang="ts">
    import { Link } from '@inertiajs/svelte'

    let { href, label }: { href: string; label: string } = $props()
</script>

<Link
    {href}
    class="focus-ring relative mb-3.5 inline-flex items-center gap-1.5 rounded-sm bg-transparent py-1 text-[13px] font-medium text-muted-foreground no-underline after:absolute after:-inset-x-2 after:-inset-y-2.5 after:content-[''] sm:hidden"
    data-testid="back-link"
>
    ← {label}
</Link>
