<script lang="ts">
    // Copyright 2026 Monoven Ltd. All rights reserved.
    // Licensed under the Elastic License v2 (ELv2).
    // See LICENSE file in the project root.
    import { router } from '@inertiajs/svelte'
    import { untrack } from 'svelte'
    import FieldError from '@/components/FieldError.svelte'
    import { Button } from '@/components/ui/button'
    import * as Dialog from '@/components/ui/dialog'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import { Textarea } from '@/components/ui/textarea'
    import { clock, cronExpression, readClock, readMinute, RUNS, WEEKDAYS } from '@/lib/cron'
    import type { CronJob, CronPresetKind, CronSend } from '@/lib/cron'

    type Errors = { command?: string; schedule?: string; minute?: string; time?: string; description?: string }

    let {
        open = $bindable(false),
        job,
        storeHref,
        example,
        busy,
        send
    }: {
        open: boolean
        job: CronJob | null
        storeHref: string
        example: string
        busy: boolean
        send: CronSend
    } = $props()

    let command = $state('')
    let kind = $state<CronPresetKind>('daily')
    let minute = $state<string | number | null>('0')
    let time = $state('03:00')
    let weekday = $state('1')
    let expression = $state('')
    let description = $state('')
    let errors = $state<Errors>({})

    $effect(() => {
        if (open) {
            untrack(reset)
        }
    })

    function reset() {
        const preset = job?.preset ?? { kind: 'daily', minute: 0, hour: 3, weekday: 1 }

        command = job?.command ?? ''
        kind = preset.kind
        minute = String(preset.minute)
        time = clock(preset.hour, preset.minute)
        weekday = String(preset.weekday)
        expression = preset.kind === 'custom' ? (job?.schedule ?? '') : ''
        description = job?.description ?? ''
        errors = {}
    }

    function schedule(target: CronPresetKind): string | null {
        if (target === 'hourly') {
            const past = readMinute(minute)

            return past === null ? null : cronExpression('hourly', past, 0, 0, '')
        }

        if (target === 'daily' || target === 'weekly') {
            const at = readClock(time)

            return at === null ? null : cronExpression(target, at.minute, at.hour, Number(weekday), '')
        }

        return cronExpression(target, 0, 0, 0, expression)
    }

    function choose(event: Event) {
        const next = (event.currentTarget as HTMLSelectElement).value as CronPresetKind

        if (next === 'custom' && expression.trim() === '') {
            expression = schedule(kind) ?? ''
        }

        kind = next
        errors = { ...errors, schedule: undefined, minute: undefined, time: undefined }
    }

    function clear(field: keyof Errors) {
        errors = { ...errors, [field]: undefined }
    }

    function submit(event: SubmitEvent) {
        event.preventDefault()

        if (kind === 'hourly' && readMinute(minute) === null) {
            errors = { ...errors, minute: 'Pick a minute from 0 to 59.' }

            return
        }

        if ((kind === 'daily' || kind === 'weekly') && readClock(time) === null) {
            errors = { ...errors, time: 'Pick a time, like 03:00.' }

            return
        }

        const data = { schedule: schedule(kind) ?? '', command, description }
        const target = job

        send((finish) => {
            const options = {
                preserveScroll: true,
                onSuccess: () => (open = false),
                onError: (failed: Record<string, string>) =>
                    (errors = { command: failed.command, schedule: failed.schedule, description: failed.description }),
                onFinish: finish
            }

            if (target) {
                router.put(target.href, data, options)
            } else {
                router.post(storeHref, data, options)
            }
        })
    }
</script>

<Dialog.Root bind:open>
    <Dialog.Content>
        <form onsubmit={submit} novalidate>
            <Dialog.Header>
                <Dialog.Title>{job ? 'Edit cron job' : 'Add a cron job'}</Dialog.Title>
                <Dialog.Description
                    >It runs in the current release with the app's permissions and environment variables. Keep secrets
                    in those variables, not in the command.</Dialog.Description
                >
            </Dialog.Header>

            <div class="mt-4 flex flex-col gap-4">
                <div>
                    <Label for="cron-command" class="mb-1.5">Command</Label>
                    <Textarea
                        id="cron-command"
                        bind:value={command}
                        oninput={() => clear('command')}
                        rows={3}
                        class="font-mono text-[12.5px] leading-[1.7]"
                        placeholder={example}
                        aria-invalid={errors.command ? true : undefined}
                        spellcheck="false"
                    />
                    <FieldError message={errors.command} />
                </div>

                <div class="flex flex-col gap-3">
                    <div>
                        <Label for="cron-runs" class="mb-1.5">Runs</Label>
                        <NativeSelect
                            id="cron-runs"
                            value={kind}
                            options={RUNS}
                            onchange={choose}
                            aria-invalid={errors.schedule && kind !== 'custom' ? true : undefined}
                        />
                    </div>

                    {#if kind === 'hourly'}
                        <div>
                            <Label for="cron-minute" class="mb-1.5">Minute past the hour</Label>
                            <Input
                                id="cron-minute"
                                type="number"
                                min="0"
                                max="59"
                                bind:value={minute}
                                oninput={() => clear('minute')}
                                aria-invalid={errors.minute ? true : undefined}
                            />
                            <FieldError message={errors.minute} />
                        </div>
                    {:else if kind === 'daily' || kind === 'weekly'}
                        <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                            {#if kind === 'weekly'}
                                <div>
                                    <Label for="cron-day" class="mb-1.5">Day</Label>
                                    <NativeSelect id="cron-day" bind:value={weekday} options={WEEKDAYS} />
                                </div>
                            {/if}
                            <div>
                                <Label for="cron-time" class="mb-1.5">Time, UTC</Label>
                                <Input
                                    id="cron-time"
                                    type="time"
                                    bind:value={time}
                                    oninput={() => clear('time')}
                                    aria-invalid={errors.time ? true : undefined}
                                />
                            </div>
                        </div>
                        <FieldError message={errors.time} />
                    {:else if kind === 'custom'}
                        <div>
                            <Label for="cron-expression" class="mb-1.5">Expression</Label>
                            <Input
                                id="cron-expression"
                                bind:value={expression}
                                oninput={() => clear('schedule')}
                                class="font-mono text-[13px]"
                                placeholder="*/5 * * * *"
                                autocomplete="off"
                                spellcheck="false"
                                aria-describedby="cron-expression-help"
                                aria-invalid={errors.schedule ? true : undefined}
                            />
                            <p id="cron-expression-help" class="mt-1.5 text-[12px] text-muted-foreground">
                                Minute, hour, day of the month, month and day of the week. <span class="font-mono"
                                    >*/5 * * * *</span
                                > runs every 5 minutes.
                            </p>
                        </div>
                    {/if}

                    <FieldError message={errors.schedule} />
                </div>

                <div>
                    <Label for="cron-description" class="mb-1.5">Description</Label>
                    <Input
                        id="cron-description"
                        bind:value={description}
                        oninput={() => clear('description')}
                        maxlength={80}
                        placeholder="Nightly backup"
                        autocomplete="off"
                        aria-describedby="cron-description-help"
                        aria-invalid={errors.description ? true : undefined}
                    />
                    <p id="cron-description-help" class="mt-1.5 text-[12px] text-muted-foreground">
                        Optional. Shown in the list instead of the command.
                    </p>
                    <FieldError message={errors.description} />
                </div>
            </div>

            <Dialog.Footer>
                <Button variant="ghost" type="button" onclick={() => (open = false)}>Cancel</Button>
                <Button type="submit" disabled={busy}>{job ? 'Save' : 'Add job'}</Button>
            </Dialog.Footer>
        </form>
    </Dialog.Content>
</Dialog.Root>
