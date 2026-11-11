<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import MarkerUnderline from '@/components/MarkerUnderline.svelte'
    import MonovenMark from '@/components/MonovenMark.svelte'
    import Wordmark from '@/components/panel/Wordmark.svelte'

    type Status = {
        stage: string | null
        saved: string | null
        removed: string[]
        failed: { name: string; file: string; comment: string }[]
    }

    let { address, health, status: initial }: { address: string; health: string; status: Status } = $props()

    const MISSES = 5
    const ORDER = ['saving', 'releasing', 'stopping', 'removing']

    let status = $derived<Status>(initial)
    let misses = $state(0)
    let gone = $state(false)

    const reached = (stage: string) => ORDER.indexOf(status.stage ?? '') >= ORDER.indexOf(stage)
    const servers = $derived(status.removed.length + status.failed.length)

    $effect(() => {
        if (gone) {
            return
        }

        const timer = setInterval(poll, 1000)

        return () => clearInterval(timer)
    })

    async function poll() {
        const next = await fetch(route('settings.removal.status'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then((response) =>
                response.ok && response.headers.get('content-type')?.includes('application/json')
                    ? (response.json() as Promise<Status>)
                    : null
            )
            .catch(() => null)

        if (next) {
            status = next
            misses = 0

            return
        }

        misses += 1

        if (misses >= MISSES && !(await answers())) {
            gone = true
        }
    }

    async function answers(): Promise<boolean> {
        try {
            return (await fetch(health, { cache: 'no-store' })).ok
        } catch {
            return false
        }
    }
</script>

<AppHead title={gone ? 'Coresmith is gone' : 'Removing Coresmith'} />

<div class="panel grain relative flex min-h-dvh flex-col bg-background font-sans text-foreground">
    <header class="relative z-10 mx-auto flex w-full max-w-300 items-center px-10 py-5.5 max-sm:px-5">
        <Wordmark />
    </header>

    <main class="relative z-10 mx-auto w-full max-w-300 flex-1 px-10 pt-12 pb-12 max-sm:px-5 max-sm:pt-6">
        {#if gone}
            <h1 class="type-h1 m-0 mb-1.5" data-testid="removal-heading">
                Coresmith is <MarkerUnderline>gone</MarkerUnderline>
            </h1>
            <div class="type-body-sm flex max-w-[64ch] flex-col gap-3 text-muted-foreground" data-testid="removal-gone">
                <p class="m-0">
                    Your servers keep running.{status.saved
                        ? ` A copy of its settings and backups is in ${status.saved} on this server.`
                        : ''}
                </p>
                {#if status.failed.length > 0}
                    <div>
                        <p class="m-0 mb-1.5">Coresmith couldn't reach these servers, so its key is still on them:</p>
                        <ul
                            class="m-0 flex flex-col gap-1 pl-4.5 font-mono text-[12.5px]"
                            data-testid="removal-unreached"
                        >
                            {#each status.failed as server (server.name)}
                                <li>
                                    {server.name}: remove the line ending in {server.comment} from {server.file}.
                                </li>
                            {/each}
                        </ul>
                    </div>
                {/if}
                <p class="m-0">
                    If anything was left behind, <span class="font-mono">journalctl -u coresmith-uninstall</span> on this
                    server says what.
                </p>
            </div>
        {:else}
            <h1 class="type-h1 m-0 mb-1.5" data-testid="removal-heading">
                Removing <MarkerUnderline>Coresmith</MarkerUnderline>
            </h1>
            <p class="type-body-sm mb-6 max-w-[64ch] text-muted-foreground">
                Coresmith is removing itself from {address}. Keep this page open to see how it goes; closing it doesn't
                stop the removal.
            </p>
            <ol
                class="m-0 flex max-w-[64ch] flex-col gap-2 pl-4.5 text-[13.5px]"
                data-testid="removal-steps"
                aria-live="polite"
            >
                {#if status.stage === null}
                    <li>Waiting for Coresmith's helper to start.</li>
                {/if}
                {#if reached('releasing') && status.saved}
                    <li>Saved a copy to <span class="font-mono">{status.saved}</span></li>
                {:else if reached('saving')}
                    <li>Saving a copy of its settings and backups…</li>
                {/if}
                {#if reached('stopping') && servers > 0}
                    <li>Took Coresmith's key off {status.removed.length} of {servers} servers</li>
                {:else if reached('releasing')}
                    <li>Taking Coresmith's key off its servers…</li>
                {/if}
                {#if reached('stopping')}
                    <li>Stopping Coresmith…</li>
                {/if}
            </ol>
        {/if}
    </main>

    <footer class="relative z-10 border-t border-border">
        <div class="mx-auto flex w-full max-w-300 flex-wrap items-center gap-6 px-10 py-8 max-sm:px-5">
            <span class="text-[12.5px] text-muted-foreground">© 2026 Monoven Ltd · coresmith.io · ELv2 licensed</span>
            <MonovenMark class="ml-auto" />
        </div>
    </footer>
</div>
