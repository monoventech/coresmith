<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import { Breadcrumb, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator, Item } from '@/components/ui/breadcrumb'

    interface BreadcrumbItem {
        title: string
        href?: string
    }

    interface Props {
        breadcrumbs: BreadcrumbItem[]
    }

    let { breadcrumbs }: Props = $props()
</script>

<Breadcrumb>
    <BreadcrumbList>
        {#each breadcrumbs as item, index (index)}
            <Item>
                {#if index === breadcrumbs.length - 1}
                    <BreadcrumbPage>{item.title}</BreadcrumbPage>
                {:else}
                    <BreadcrumbLink>
                        <Link href={item.href ?? '#'}>{item.title}</Link>
                    </BreadcrumbLink>
                {/if}
            </Item>
            {#if index !== breadcrumbs.length - 1}
                <BreadcrumbSeparator />
            {/if}
        {/each}
    </BreadcrumbList>
</Breadcrumb>
