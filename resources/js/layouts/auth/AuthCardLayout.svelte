<script lang="ts">
    import { Link } from '@inertiajs/svelte'
    import type { Snippet } from 'svelte'
    import AppLogoIcon from '@/components/AppLogoIcon.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

    let {
        title = '',
        description = '',
        children
    }: {
        title?: string
        description?: string
        children?: Snippet
    } = $props()
</script>

<div class="flex min-h-svh flex-col items-center justify-center gap-8 bg-muted p-6 md:p-10">
    <div class="flex w-full max-w-md flex-col gap-6">
        <Link href={route('home')} class="flex w-fit items-center gap-2.5">
            <AppLogoIcon class="size-7 text-primary" />
        </Link>

        <Card class="shadow-md">
            <CardHeader class="px-8 pt-8 pb-0">
                <CardTitle class="text-2xl leading-tight font-semibold tracking-[-0.01em]">
                    <MarkerUnderline>{title}</MarkerUnderline>
                </CardTitle>
                <CardDescription class="text-base">
                    {description}
                </CardDescription>
            </CardHeader>
            <CardContent class="px-8 py-8">
                {@render children?.()}
            </CardContent>
        </Card>
    </div>

    <MonovenMark />
</div>
