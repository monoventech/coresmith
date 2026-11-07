<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'
    import { Badge } from '@/components/ui/badge'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
    import * as RadioGroup from '@/components/ui/radio-group'
    import type { RuntimeChoices, RuntimeOption } from '@/lib/runtimes'

    let { runtime, error }: { runtime: RuntimeChoices; error?: string } = $props()

    const REPOSITORY = 'repository'

    let selected = $state(REPOSITORY)
    let previous = REPOSITORY
    let acknowledged = $state(false)
    let pending = $state<RuntimeOption | null>(null)
    let group = $state<HTMLElement | null>(null)

    function changed(value: string) {
        acknowledged = false

        const option = runtime.options.find((candidate) => candidate.line === value)

        if (option?.status.state === 'eol') {
            pending = option

            return
        }

        previous = value
    }

    function keep() {
        acknowledged = true
        previous = selected
        pending = null
    }

    function revert() {
        selected = previous
        pending = null
    }

    function restoreFocus(event: Event) {
        event.preventDefault()
        group?.querySelector<HTMLElement>(`[data-value="${selected}"]`)?.focus()
    }
</script>

<div>
    <Label id="runtime-version-label" class="mb-1.5">{`${runtime.label} version`}</Label>
    <RadioGroup.Root
        bind:ref={group}
        bind:value={selected}
        onValueChange={changed}
        aria-labelledby="runtime-version-label"
        class="gap-0 overflow-hidden rounded-lg border border-border"
    >
        <label class="flex cursor-pointer items-center gap-3 border-b border-border px-3.5 py-2.5 hover:bg-muted">
            <RadioGroup.Item value={REPOSITORY} aria-labelledby="runtime-version-repository" />
            <span id="runtime-version-repository" class="flex min-w-0 flex-col">
                <span class="text-[13.5px] font-medium">From the repository</span>
                <span class="text-[12px] text-muted-foreground">{runtime.sources}</span>
            </span>
        </label>
        {#each runtime.options as option (option.line)}
            <label
                class="flex cursor-pointer items-center gap-3 border-b border-border px-3.5 py-2.5 last:border-b-0 hover:bg-muted"
            >
                <RadioGroup.Item value={option.line} aria-labelledby={`runtime-version-${option.line}`} />
                <span
                    id={`runtime-version-${option.line}`}
                    class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2.5 gap-y-1"
                >
                    <span class="font-mono text-[13px]">{option.version}</span>
                    {#if option.line === runtime.recommended}<span class="text-[12px] text-muted-foreground"
                            >recommended</span
                        >{/if}
                    {#if option.status.label}<Badge variant={option.status.tone}>{option.status.label}</Badge>{/if}
                </span>
            </label>
        {/each}
    </RadioGroup.Root>
    <p class="mt-1.5 text-[12px] text-muted-foreground">Used only when the repository does not name a version.</p>
    <input type="hidden" name="runtime_version" value={selected === REPOSITORY ? '' : selected} />
    <input type="hidden" name="acknowledge_eol" value={acknowledged ? '1' : '0'} />
    <FieldError message={error} />
</div>

<Dialog.Root open={pending !== null} onOpenChange={(open) => !open && pending !== null && revert()}>
    <Dialog.Content onCloseAutoFocus={restoreFocus}>
        <Dialog.Header>
            <Dialog.Title>{`Use ${runtime.label} ${pending?.line ?? ''}?`}</Dialog.Title>
            <Dialog.Description>
                {`${runtime.label} ${pending?.line ?? ''} no longer gets security fixes, so known exploits stay open. It is your server, but a supported version is safer.`}
            </Dialog.Description>
        </Dialog.Header>
        <Dialog.Footer>
            <Button variant="ghost" onclick={revert}>Pick another version</Button>
            <Button onclick={keep}>{`Use ${runtime.label} ${pending?.line ?? ''}`}</Button>
        </Dialog.Footer>
    </Dialog.Content>
</Dialog.Root>
