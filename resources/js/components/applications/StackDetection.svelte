<script lang="ts">
    import type { Detection } from '@/lib/detection'
    import { FolderGit, KeyRound, TriangleAlert } from '@lucide/svelte'

    let {
        detection,
        reading,
        server,
        editing = false
    }: { detection: Detection | null; reading: boolean; server: string; editing?: boolean } = $props()

    const privateHosts = $derived(
        detection?.state === 'read' ? (detection.packages ?? []).map((found) => found.host) : []
    )
    const packagesHref = $derived(detection?.state === 'read' ? (detection.packages_href ?? null) : null)
    const submodules = $derived(detection?.state === 'read' ? (detection.submodules ?? []) : [])
    const fetched = $derived(submodules.filter((module) => module.refused === null))

    const blocked = $derived(
        detection?.state === 'read' && detection.verdict === 'blocked'
            ? detection.blockers
            : detection?.state === 'failed' && detection.definitive
              ? [{ field: 'repository', reason: detection.failure, fix: '' }]
              : []
    )
</script>

<div data-testid="stack-detection" aria-live="polite">
    {#if reading}
        <p class="m-0 flex items-center gap-2 text-[12.5px] text-muted-foreground">
            <span class="status-dot-pulse size-1.75 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
            <span>Reading the repository on <span class="font-mono text-[12px]">{server}</span>…</span>
        </p>
    {:else if blocked.length > 0}
        <div class="tint-warning flex gap-3 rounded-lg border px-3.5 py-3">
            <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" />
            <div class="flex min-w-0 flex-col gap-1.5 text-[13px]">
                <span class="font-semibold">
                    {detection?.state === 'failed'
                        ? "Coresmith couldn't read this repository"
                        : "This repository can't deploy as is"}
                </span>
                {#each blocked as blocker (blocker.reason)}
                    <span class="wrap-anywhere"
                        >{blocker.reason}{#if blocker.fix}<span class="font-medium">{` ${blocker.fix}`}</span
                            >{/if}</span
                    >
                {/each}
            </div>
        </div>
    {:else if detection?.state === 'read'}
        <p class="m-0 text-[12.5px] text-muted-foreground">
            {#if detection.verdict === 'ready'}
                Filled in from the repository{#if detection.commit}&#32;at
                    <span class="font-mono text-[12px]">{detection.commit.slice(0, 7)}</span>{/if}. Check it, then {editing
                    ? 'save the setup'
                    : 'add the app'}.
            {:else}
                Filled in what the repository settles. The fields marked below need you.
            {/if}
        </p>
    {:else if detection?.state === 'failed'}
        <p class="m-0 text-[12.5px] text-muted-foreground">
            {detection.failure}
            {editing
                ? 'You can still save. If your change needs a check, the app page asks for one before the next deploy.'
                : 'You can add the app now, and Coresmith checks the repository before the first deploy.'}
        </p>
    {:else if detection?.state === 'needs_key'}
        <p class="m-0 text-[12.5px] text-muted-foreground">
            {editing
                ? "This form can't read git@ repositories, since Coresmith reads them with the app's deploy key. If your change needs a check, the app page asks for one before the next deploy."
                : "Coresmith reads git@ repositories with this app's deploy key, which it makes when you add the app. Add the key to the repository, then check it on the app page before the first deploy."}
        </p>
    {:else if detection?.state === 'server_not_ready'}
        <p class="m-0 text-[12.5px] text-muted-foreground">
            Coresmith reads the repository once {detection.server} is ready. {editing
                ? 'You can save now, and the app page asks for a check before the next deploy if your change needs one.'
                : 'You can add the app now and check it before the first deploy.'}
        </p>
    {/if}

    {#if !reading && privateHosts.length > 0}
        <div class="mt-2.5 flex gap-3 rounded-lg border border-border px-3.5 py-3" data-testid="private-packages-note">
            <KeyRound class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <p class="m-0 text-[13px]">
                This app installs packages from {#each privateHosts as host, index (host)}{#if index > 0}{index ===
                        privateHosts.length - 1
                            ? ' and '
                            : ', '}{/if}<span class="font-mono text-[12px] wrap-anywhere">{host}</span>{/each}, and
                Coresmith has no credentials for {privateHosts.length === 1 ? 'it' : 'them'}. If those packages are
                private,
                {#if packagesHref}
                    <a href={packagesHref} class="font-medium underline underline-offset-2"
                        >add credentials to Private packages in Settings, Git</a
                    > before the first deploy.
                {:else}
                    ask an owner or admin to add credentials to Private packages in Settings, Git before the first
                    deploy.
                {/if}
            </p>
        </div>
    {/if}

    {#if !reading && submodules.length > 0}
        <div class="mt-2.5 flex gap-3 rounded-lg border border-border px-3.5 py-3" data-testid="submodules-note">
            <FolderGit class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <div class="flex min-w-0 flex-col gap-1.5 text-[13px]">
                {#if fetched.length > 0}
                    <p class="m-0">
                        Each deploy fetches this repository's submodules at the commits it pins: {#each fetched as module, index (module.path)}{#if index > 0}{index ===
                                fetched.length - 1
                                    ? ' and '
                                    : ', '}{/if}<span class="font-mono text-[12px] wrap-anywhere">{module.path}</span>
                            from <span class="font-mono text-[12px] wrap-anywhere">{module.source}</span>{/each}. A
                        private one is fetched only when Coresmith's GitHub app can read it.
                    </p>
                {/if}
                {#each submodules.filter((module) => module.refused !== null) as module (module.path)}
                    <p class="m-0 text-warning-strong">{module.refused}</p>
                {/each}
            </div>
        </div>
    {/if}
</div>
