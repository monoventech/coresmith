<script module lang="ts">
    export const layout = { detail: true }
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte'
    import RepositoryPicker from '@/components/applications/RepositoryPicker.svelte'
    import RuntimeVersionPicker from '@/components/applications/RuntimeVersionPicker.svelte'
    import StackDetection from '@/components/applications/StackDetection.svelte'
    import BackLink from '@/components/BackLink.svelte'
    import DnsRecords from '@/components/DnsRecords.svelte'
    import FieldError from '@/components/FieldError.svelte'
    import EnvironmentSelect from '@/components/projects/EnvironmentSelect.svelte'
    import TextLink from '@/components/TextLink.svelte'
    import { Button } from '@/components/ui/button'
    import { Input } from '@/components/ui/input'
    import { Label } from '@/components/ui/label'
    import { NativeSelect } from '@/components/ui/native-select'
    import type { Detection, DetectionRequest } from '@/lib/detection'
    import { detectionId, detectStack } from '@/lib/detection'
    import type { DnsTarget } from '@/lib/dns'
    import { namedTargets } from '@/lib/dns'
    import type { GitHubPick, GitHubSource } from '@/lib/github'
    import type { RuntimeChoices } from '@/lib/runtimes'
    import { pickerRuntime } from '@/lib/runtimes'
    import { Form } from '@inertiajs/svelte'
    import { onDestroy, untrack } from 'svelte'

    let {
        server,
        runtimes,
        github,
        projects = [],
        js_frameworks = [],
        python_frameworks = [],
        go_frameworks = []
    }: {
        server: {
            id: string
            name: string
            href: string
            dns_targets: DnsTarget[]
        }
        runtimes: Record<string, RuntimeChoices>
        github: { connections: GitHubSource[]; settings_href: string }
        projects: { id: string; name: string; environments: { id: string; name: string }[] }[]
        js_frameworks: { value: string; label: string }[]
        python_frameworks: { value: string; label: string }[]
        go_frameworks: { value: string; label: string }[]
    } = $props()

    const FRAMEWORKS: Record<string, { value: string; label: string }[]> = $derived({
        php: [
            { value: 'laravel', label: 'Laravel' },
            { value: 'symfony', label: 'Symfony' },
            { value: '', label: 'Plain PHP' }
        ],
        js: [...js_frameworks, { value: '', label: 'API or other' }],
        ruby: [
            { value: 'rails', label: 'Rails' },
            { value: 'sinatra', label: 'Sinatra' },
            { value: 'rack', label: 'Rack' }
        ],
        python: [...python_frameworks, { value: '', label: 'Other framework' }],
        go: go_frameworks,
        static: [{ value: '', label: 'No framework' }]
    })

    const GIT_URL = 'url'

    const DEFAULT_BRANCH = 'main'

    const WAIT_MS = 700

    const STACK_FIELDS = ['type', 'framework', 'js_runtime', 'web_directory', 'main_package']

    const UNSET = 'unset'

    const SERVES_FILES = ['php', 'static']

    const STEPS = [
        { name: 'Clone release', desc: 'fetched with a one-hour token or a read-only deploy key' },
        { name: 'Install dependencies', desc: 'composer, bun, bundler, uv or go' },
        { name: 'Build assets', desc: 'into a new timestamped directory' },
        { name: 'Run migrations', desc: 'against the new release' },
        { name: 'Health check', desc: 'before anything goes live' },
        { name: 'Symlink swap', desc: 'atomic, the old release kept for rollback' }
    ]

    let type = $state('php')
    let framework = $derived.by(() => {
        const language = type

        return untrack(() => FRAMEWORKS[language]?.[0]?.value ?? '')
    })
    let typeSelect = $state<HTMLSelectElement | null>(null)
    let frameworkSelect = $state<HTMLSelectElement | null>(null)
    let jsRuntime = $state('bun')
    let webDirectory = $state('/public')
    let mainPackage = $state('')
    let mainPackages = $state<string[]>([])
    const picker = $derived(pickerRuntime(type, jsRuntime))
    let pickedSize = $state<string | null>(null)
    let domainName = $state('')
    const size = $derived(pickedSize ?? (type === 'ruby' ? 'medium' : 'small'))
    let chosenSource = $state<string | null>(null)
    const source = $derived(
        chosenSource !== null &&
            (chosenSource === GIT_URL || github.connections.some((candidate) => candidate.id === chosenSource))
            ? chosenSource
            : (github.connections[0]?.id ?? GIT_URL)
    )
    const connection = $derived(github.connections.find((candidate) => candidate.id === source) ?? null)
    let branch = $state(DEFAULT_BRANCH)
    let branchEdited = $state(false)
    let branches = $state<string[]>([])
    let repositoryUrl = $state('')
    let pickedRepository = $state<GitHubPick | null>(null)
    let detection = $state<Detection | null>(null)
    let folder = $state('/')
    let folders = $state<string[]>([])
    let reading = $state(false)
    let touched = $state<Record<string, boolean>>({})
    let controller: AbortController | null = null
    let timer: ReturnType<typeof setTimeout> | undefined

    const read = $derived(detection?.state === 'read' ? detection : null)
    const changedStack = $derived(STACK_FIELDS.some((field) => touched[field]))
    const refused = $derived(
        (read?.verdict === 'blocked' && !changedStack) || (detection?.state === 'failed' && detection.definitive)
    )

    onDestroy(() => {
        clearTimeout(timer)
        controller?.abort()
    })

    $effect(() => {
        typeSelect?.setCustomValidity(type === UNSET ? 'Pick a language.' : '')
    })

    $effect(() => {
        frameworkSelect?.setCustomValidity(framework === UNSET ? 'Pick a framework.' : '')
    })

    function chooseSource(value: string) {
        chosenSource = value
        branches = []
        pickedRepository = null
        leaveFolder()

        if (!branchEdited) {
            branch = DEFAULT_BRANCH
        }

        forget()
    }

    function picked(pick: GitHubPick) {
        const repositoryChanged = pickedRepository?.id !== pick.id

        if (!branchEdited) {
            branch = pick.default_branch
        }

        branches = pick.branches
        pickedRepository = pick

        if (repositoryChanged) {
            leaveFolder()
            detectSoon(0)
        }
    }

    function leaveFolder() {
        folder = '/'
        folders = []
    }

    function pickFolder(value: string) {
        folder = value
        touched = {}
        detectSoon(0)
    }

    function forget() {
        clearTimeout(timer)
        controller?.abort()
        reading = false
        detection = null
    }

    function wanted(): DetectionRequest | null {
        if (branch.trim() === '') {
            return null
        }

        const directory = folder === '/' ? {} : { directory: folder }

        if (connection && pickedRepository) {
            return {
                source_provider_id: connection.id,
                repository_id: pickedRepository.id,
                repository_branch: branch,
                ...directory
            }
        }

        return !connection && repositoryUrl.trim() !== ''
            ? { repository_url: repositoryUrl.trim(), repository_branch: branch, ...directory }
            : null
    }

    function detectSoon(wait = WAIT_MS) {
        clearTimeout(timer)
        timer = setTimeout(detect, wait)
    }

    async function detect() {
        controller?.abort()

        const request = wanted()

        if (request === null) {
            forget()

            return
        }

        if (request.repository_url !== undefined && !request.repository_url.startsWith('https://')) {
            reading = false
            detection = request.repository_url.startsWith('git@') ? { state: 'needs_key' } : null

            return
        }

        const current = new AbortController()
        controller = current
        reading = true

        const answer = await detectStack(route('servers.repository-stack', server.id), request, current.signal)

        if (current.signal.aborted) {
            return
        }

        reading = false
        detection = answer

        if (answer.state === 'read') {
            folders = answer.folders ?? []
            mainPackages = answer.options?.main_package ?? []
            fill(answer.fields, answer.unsettled)
        }
    }

    function fill(
        fields: {
            type: string | null
            framework: string | null
            js_runtime: string | null
            web_directory: string | null
            main_package?: string | null
        },
        unsettled: Record<string, string>
    ) {
        if (!touched.type && (fields.type !== null || unsettled.type)) {
            type = fields.type ?? UNSET
        }

        if (!touched.framework && !touched.type && (fields.framework !== null || unsettled.framework)) {
            framework = fields.framework ?? UNSET
        }

        if (fields.js_runtime !== null && !touched.js_runtime) {
            jsRuntime = fields.js_runtime
        }

        if (fields.web_directory !== null && !touched.web_directory) {
            webDirectory = fields.web_directory
        }

        if (!touched.main_package) {
            mainPackage = fields.main_package ?? ''
        }
    }

    function touch(field: string) {
        touched = { ...touched, [field]: true }
    }
</script>

{#snippet note(field: string)}
    {#if read && !touched[field]}
        {#if read.unsettled[field]}
            <p class="mt-1.5 mb-0 text-[12px] text-warning-strong">{read.unsettled[field]}</p>
        {:else if read.sources[field]}
            <p class="mt-1.5 mb-0 text-[12px] wrap-anywhere text-muted-foreground">{read.sources[field]}</p>
        {/if}
    {/if}
{/snippet}

<AppHead title="Add an app" />

<BackLink href={server.href} label={server.name} />

<h1 class="type-h1 m-0 mb-1.5">Add an app</h1>
<p class="type-body-sm mb-7 text-muted-foreground">
    It runs on <span class="font-mono text-[13px]">{server.name}</span>, walled off from the other apps there.
</p>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card p-6">
        <Form method="post" action={route('servers.applications.store', server.id)}>
            {#snippet children({ errors, processing })}
                <input type="hidden" name="directory" value={folder} />
                <input type="hidden" name="detection" value={detectionId(detection)} />
                <div class="flex flex-col gap-4">
                    <div>
                        <Label for="name" class="mb-1.5">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            required
                            placeholder="atlas"
                            autocomplete="off"
                            aria-invalid={errors.name ? true : undefined}
                        />
                        <FieldError message={errors.name} />
                    </div>
                    {#if github.connections.length > 0}
                        <div>
                            <Label for="source" class="mb-1.5">Source</Label>
                            <NativeSelect
                                id="source"
                                bind:value={() => source, chooseSource}
                                options={[
                                    ...github.connections.map((option) => ({
                                        value: option.id,
                                        label: `GitHub · ${option.account}`
                                    })),
                                    { value: GIT_URL, label: 'A Git URL' }
                                ]}
                                aria-invalid={errors.source_provider_id ? true : undefined}
                            />
                            <FieldError message={errors.source_provider_id} />
                        </div>
                    {/if}
                    {#if connection}
                        {#key connection.id}
                            <RepositoryPicker {connection} error={errors.repository_id} onpick={picked} />
                        {/key}
                    {:else}
                        <div>
                            <Label for="repository_url" class="mb-1.5">Repository</Label>
                            <Input
                                id="repository_url"
                                name="repository_url"
                                bind:value={repositoryUrl}
                                oninput={() => {
                                    leaveFolder()
                                    detectSoon()
                                }}
                                placeholder="git@github.com:you/atlas.git"
                                autocomplete="off"
                                class="font-mono text-[13px]"
                                aria-invalid={errors.repository_url ? true : undefined}
                            />
                            <FieldError message={errors.repository_url} />
                            {#if github.connections.length === 0}
                                <FieldError message={errors.source_provider_id} />
                            {/if}
                            {#if github.connections.length === 0 && !errors.repository_url}
                                <p class="mt-1.5 text-[12px] text-muted-foreground">
                                    Connect GitHub in <TextLink href={github.settings_href}>Settings, Git</TextLink> to pick
                                    a private repository from a list.
                                </p>
                            {/if}
                        </div>
                    {/if}
                    <div>
                        <Label for="repository_branch" class="mb-1.5">Branch</Label>
                        <Input
                            id="repository_branch"
                            name="repository_branch"
                            bind:value={branch}
                            oninput={() => {
                                branchEdited = true
                                detectSoon()
                            }}
                            list={branches.length > 0 ? 'repository-branches' : undefined}
                            required
                            autocomplete="off"
                            class="font-mono text-[13px]"
                            aria-invalid={errors.repository_branch ? true : undefined}
                        />
                        {#if branches.length > 0}
                            <datalist id="repository-branches">
                                {#each branches as name (name)}
                                    <option value={name}></option>
                                {/each}
                            </datalist>
                        {/if}
                        <FieldError message={errors.repository_branch} />
                    </div>
                    {#if folders.length > 0}
                        <div>
                            <Label for="folder" class="mb-1.5">Folder</Label>
                            <NativeSelect
                                id="folder"
                                bind:value={() => folder, pickFolder}
                                options={[
                                    { value: '/', label: '/ (repository root)' },
                                    ...folders.map((path) => ({ value: path, label: path }))
                                ]}
                                class="font-mono text-[13px]"
                            />
                            <FieldError message={errors.directory} />
                        </div>
                    {/if}
                    <StackDetection {detection} {reading} server={server.name} />
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label for="type" class="mb-1.5">Language</Label>
                            <NativeSelect
                                id="type"
                                name="type"
                                bind:ref={typeSelect}
                                bind:value={type}
                                onchange={() => touch('type')}
                                options={[
                                    ...(type === UNSET ? [{ value: UNSET, label: 'Pick one', disabled: true }] : []),
                                    { value: 'php', label: 'PHP' },
                                    { value: 'js', label: 'JavaScript' },
                                    { value: 'ruby', label: 'Ruby' },
                                    { value: 'python', label: 'Python' },
                                    { value: 'go', label: 'Go' },
                                    { value: 'static', label: 'Static site' }
                                ]}
                                aria-invalid={errors.type ? true : undefined}
                            />
                            {@render note('type')}
                            <FieldError message={errors.type} />
                        </div>
                        <div>
                            <Label for="framework" class="mb-1.5">Framework</Label>
                            <NativeSelect
                                id="framework"
                                name="framework"
                                bind:ref={frameworkSelect}
                                bind:value={framework}
                                onchange={() => touch('framework')}
                                options={[
                                    ...(framework === UNSET || type === UNSET
                                        ? [{ value: UNSET, label: 'Pick one', disabled: true }]
                                        : []),
                                    ...(FRAMEWORKS[type] ?? [])
                                ]}
                                aria-invalid={errors.framework ? true : undefined}
                            />
                            {@render note('framework')}
                            <FieldError message={errors.framework} />
                        </div>
                    </div>
                    {#if type === 'js'}
                        <div>
                            <Label for="js_runtime" class="mb-1.5">Runtime</Label>
                            <NativeSelect
                                id="js_runtime"
                                name="js_runtime"
                                bind:value={jsRuntime}
                                onchange={() => touch('js_runtime')}
                                options={[
                                    { value: 'bun', label: 'Bun' },
                                    { value: 'node', label: 'Node' },
                                    { value: 'deno', label: 'Deno' }
                                ]}
                                aria-invalid={errors.js_runtime ? true : undefined}
                            />
                            {@render note('js_runtime')}
                            <FieldError message={errors.js_runtime} />
                        </div>
                    {/if}
                    {#if picker && runtimes[picker]}
                        {#key picker}
                            <RuntimeVersionPicker
                                runtime={runtimes[picker]}
                                error={errors.runtime_version}
                                detected={read?.sources.runtime_version ?? null}
                            />
                        {/key}
                        {#if read?.unsettled.runtime_version}
                            <p class="-mt-2.5 mb-0 text-[12px] text-warning-strong">{read.unsettled.runtime_version}</p>
                        {/if}
                    {/if}
                    {#if type === 'go'}
                        <div>
                            <Label for="main_package" class="mb-1.5">Main package</Label>
                            {#if mainPackages.length > 1}
                                <NativeSelect
                                    id="main_package"
                                    name="main_package"
                                    bind:value={mainPackage}
                                    onchange={() => touch('main_package')}
                                    options={[
                                        ...(mainPackage === '' ? [{ value: '', label: 'Pick one' }] : []),
                                        ...mainPackages.map((path) => ({
                                            value: path,
                                            label: path === '.' ? '. (module root)' : path
                                        }))
                                    ]}
                                    required
                                    class="font-mono text-[13px]"
                                    aria-invalid={errors.main_package ? true : undefined}
                                />
                            {:else}
                                <Input
                                    id="main_package"
                                    name="main_package"
                                    bind:value={mainPackage}
                                    oninput={() => touch('main_package')}
                                    placeholder="cmd/server"
                                    required
                                    class="font-mono text-[13px]"
                                    aria-invalid={errors.main_package ? true : undefined}
                                />
                            {/if}
                            {@render note('main_package')}
                            <FieldError message={errors.main_package} />
                        </div>
                    {/if}
                    {#if SERVES_FILES.includes(type)}
                        <div>
                            <Label for="web_directory" class="mb-1.5">Web root</Label>
                            <Input
                                id="web_directory"
                                name="web_directory"
                                bind:value={webDirectory}
                                oninput={() => touch('web_directory')}
                                required
                                class="font-mono text-[13px]"
                                aria-invalid={errors.web_directory ? true : undefined}
                            />
                            {@render note('web_directory')}
                            <FieldError message={errors.web_directory} />
                        </div>
                    {/if}
                    <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                        <div>
                            <Label for="domain" class="mb-1.5">Domain</Label>
                            <Input
                                id="domain"
                                name="domain"
                                bind:value={domainName}
                                placeholder="app.example.com"
                                autocomplete="off"
                                aria-invalid={errors.domain ? true : undefined}
                            />
                            <FieldError message={errors.domain} />
                        </div>
                        <div class="max-sm:order-2">
                            <Label for="app_size" class="mb-1.5">Size</Label>
                            <NativeSelect
                                id="app_size"
                                name="app_size"
                                bind:value={() => size, (value: string) => (pickedSize = value)}
                                options={[
                                    { value: 'small', label: 'Small, side projects' },
                                    { value: 'medium', label: 'Medium, production' },
                                    { value: 'large', label: 'Large, heavy traffic' }
                                ]}
                            />
                        </div>
                        <div class="col-span-full max-sm:order-1">
                            {#if server.dns_targets.length > 0}
                                <p class="m-0 mb-1 text-[12px] text-muted-foreground">
                                    Point the domain at this server with {server.dns_targets.length === 1
                                        ? 'this record'
                                        : 'these records'}. The certificate is issued on the first visit.
                                </p>
                                <DnsRecords
                                    records={namedTargets(server.dns_targets, domainName)}
                                    testid="domain-targets"
                                />
                            {:else}
                                <p class="m-0 text-[12px] text-muted-foreground">
                                    Point the domain at the server first: an A record for its IPv4 address, or an AAAA
                                    record for IPv6. Once the app is live, Coresmith checks the domain from outside and
                                    shows the record it needs. The certificate is issued on the first visit.
                                </p>
                            {/if}
                        </div>
                    </div>
                    <EnvironmentSelect {projects} error={errors.environment_id} />
                    <Button type="submit" class="w-full" disabled={processing || refused}>Add the app</Button>
                </div>
            {/snippet}
        </Form>
    </div>

    <div class="rounded-xl border border-border bg-card p-6">
        <h3 class="type-h3 m-0 mb-1">What a deploy does</h3>
        <p class="mb-4 text-[12.5px] text-muted-foreground">
            The current release keeps serving traffic until the last step.
        </p>
        <ol class="m-0 flex list-none flex-col gap-0.5 p-0">
            {#each STEPS as step, index (step.name)}
                <li class="flex items-baseline gap-3 py-1.75">
                    <span class="w-4 flex-none font-mono text-[12px] text-primary-strong">{index + 1}</span>
                    <span class="text-[13.5px]"
                        ><span class="font-medium">{step.name}</span><span class="text-muted-foreground"
                            >{`, ${step.desc}`}</span
                        ></span
                    >
                </li>
            {/each}
        </ol>
    </div>
</div>
