<script lang="ts" module>
    export type PhpChoice = { value: number; label: string }

    export type PhpLimits = {
        values: { uploads: number; memory: number; seconds: number; reload: 'deploy' | 'timestamps' }
        cells: { label: string; value: string }[]
        choices: { uploads: PhpChoice[]; memory: PhpChoice[]; seconds: PhpChoice[] }
        size: string
        cap: string
        held: boolean
        failure: string | null
    }
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import * as RadioGroup from '@/components/ui/radio-group'
    import { cn } from '@/lib/utils'

    let {
        php,
        server,
        href,
        canUpdate,
        class: className = ''
    }: { php: PhpLimits; server: string; href: string; canUpdate: boolean; class?: string } = $props()

    let open = $state(false)
    let saving = $state(false)
    let uploads = $state('')
    let memory = $state('')
    let seconds = $state('')
    let reload = $state<string>('deploy')
    let errors = $state<Record<string, string>>({})

    function options(list: PhpChoice[]) {
        return list.map((choice) => ({ value: String(choice.value), label: choice.label }))
    }

    function edit() {
        uploads = String(php.values.uploads)
        memory = String(php.values.memory)
        seconds = String(php.values.seconds)
        reload = php.values.reload
        errors = {}
        open = true
    }

    function save(event: SubmitEvent) {
        event.preventDefault()
        saving = true
        router.put(
            href,
            { uploads: Number(uploads), memory: Number(memory), seconds: Number(seconds), reload },
            {
                preserveScroll: true,
                onSuccess: () => (open = false),
                onError: (failed) => (errors = failed),
                onFinish: () => (saving = false)
            }
        )
    }
</script>

<section
    aria-labelledby="php-limits-title"
    class={cn(
        'flex min-w-0 flex-col gap-3.5 rounded-xl border border-border bg-card px-5.5 py-5 max-sm:hidden',
        className
    )}
    data-testid="php-limits"
>
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0 flex-1 basis-105">
            <h3 id="php-limits-title" class="type-h3 m-0 mb-1">PHP</h3>
            <p class="m-0 text-[12.5px] text-muted-foreground">
                Limits for this app's PHP, the same for the website, queue workers, cron jobs and deploys. Saving
                reloads PHP without a deploy.
            </p>
        </div>
        <Button variant="outline" size="sm" disabled={!canUpdate} onclick={edit}>Change</Button>
    </div>

    <dl class="m-0 grid grid-cols-4 border-t border-border">
        {#each php.cells as cell, index (cell.label)}
            <div
                class={cn(
                    'min-w-0 px-4 pt-3.5 pb-0.5',
                    index === 0 && 'pl-0',
                    index > 0 && 'border-l border-border',
                    index === php.cells.length - 1 && 'pr-0'
                )}
                data-testid="php-limit"
            >
                <dt class="mb-1 text-[12px] text-muted-foreground">{cell.label}</dt>
                <dd class="m-0 text-[15px] font-medium">{cell.value}</dd>
            </div>
        {/each}
    </dl>

    {#if php.held}
        <p class="m-0 text-[12.5px] text-muted-foreground" data-testid="php-held">
            Memory is held at {php.cap}, the most the {php.size} size allows.
        </p>
    {/if}

    {#if php.failure}
        <p class="m-0 text-[12.5px] text-destructive-strong" role="status" data-testid="php-failure">
            Couldn't apply the last change. {php.failure}
        </p>
    {/if}
</section>

<Dialog.Root bind:open>
    <Dialog.Content>
        <form onsubmit={save}>
            <Dialog.Header>
                <Dialog.Title>Change PHP limits</Dialog.Title>
                <Dialog.Description>Saving reloads PHP on {server} without a deploy.</Dialog.Description>
            </Dialog.Header>

            <div class="mt-4 flex flex-col gap-4">
                <div class="flex flex-col gap-1.5">
                    <Label for="php-uploads">Uploads up to</Label>
                    <NativeSelect
                        id="php-uploads"
                        bind:value={uploads}
                        options={options(php.choices.uploads)}
                        aria-describedby="php-uploads-hint"
                    />
                    <p id="php-uploads-hint" class="m-0 text-[12px] text-muted-foreground">
                        The largest request the app accepts. One file can use all of it.
                    </p>
                    <FieldError message={errors.uploads} />
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="php-memory">Memory per process</Label>
                    <NativeSelect
                        id="php-memory"
                        bind:value={memory}
                        options={options(php.choices.memory)}
                        aria-describedby="php-memory-hint"
                    />
                    <p id="php-memory-hint" class="m-0 text-[12px] text-muted-foreground">
                        The {php.size} size allows up to {php.cap}. A larger size allows more.
                    </p>
                    <FieldError message={errors.memory} />
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="php-seconds">Web requests stop after</Label>
                    <NativeSelect
                        id="php-seconds"
                        bind:value={seconds}
                        options={options(php.choices.seconds)}
                        aria-describedby="php-seconds-hint"
                    />
                    <p id="php-seconds-hint" class="m-0 text-[12px] text-muted-foreground">
                        Queue workers and cron jobs run as long as they need.
                    </p>
                    <FieldError message={errors.seconds} />
                </div>

                <div class="flex flex-col gap-2">
                    <span id="php-reload-label" class="text-sm font-medium">Load new code</span>
                    <RadioGroup.Root
                        value={reload}
                        onValueChange={(value) => (reload = value)}
                        aria-labelledby="php-reload-label"
                        aria-describedby="php-reload-hint"
                        class="gap-2"
                    >
                        <label class="flex items-center gap-2.5 text-[14px]">
                            <RadioGroup.Item value="deploy" />
                            At each deploy, which is faster
                        </label>
                        <label class="flex items-center gap-2.5 text-[14px]">
                            <RadioGroup.Item value="timestamps" />
                            Every 2 seconds
                        </label>
                    </RadioGroup.Root>
                    <p id="php-reload-hint" class="m-0 text-[12px] text-muted-foreground">
                        Pick every 2 seconds if the app writes its own PHP files, like WordPress updating its plugins.
                    </p>
                    <FieldError message={errors.reload} />
                </div>
            </div>

            <Dialog.Footer class="mt-5">
                <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                <Button type="submit" disabled={saving}>Save</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
