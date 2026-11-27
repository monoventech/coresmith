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
    import ProjectSelect from '@/components/projects/ProjectSelect.svelte'
    import TagInput from '@/components/projects/TagInput.svelte'
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
    import { firstTagError } from '@/lib/tags'
    import { Form } from '@inertiajs/svelte'
    import { TriangleAlert } from '@lucide/svelte'
    import { onDestroy, onMount, untrack } from 'svelte'

    let {
        server,
        runtimes,
        github,
        projects = [],
        project = null,
        tag_suggestions = [],
        js_frameworks = [],
        python_frameworks = [],
        go_frameworks = [],
        java_frameworks = [],
        databases = { choices: [], can_create: false },
        setup = null
    }: {
        server: {
            id: string
            name: string
            href: string
            dns_targets: DnsTarget[]
            network?: { text: string; action: string; href: string } | null
        }
        runtimes: Record<string, RuntimeChoices>
        github: { connections: GitHubSource[]; settings_href: string }
        projects: { id: string; name: string }[]
        project: string | null
        tag_suggestions: string[]
        js_frameworks: { value: string; label: string; files: boolean }[]
        python_frameworks: { value: string; label: string }[]
        go_frameworks: { value: string; label: string }[]
        java_frameworks: { value: string; label: string }[]
        databases?: { choices: { value: string; name: string; type: string }[]; can_create: boolean }
        setup?: Setup | null
    } = $props()

    type Setup = {
        action: string
        back: string
        application: {
            name: string
            source:
                | { kind: 'url'; url: string }
                | { kind: 'github'; full_name: string; account: string; connection_id: string; repository_id: string }
            branch: string
            directory: string
            type: string
            framework: string | null
            js_runtime: string | null
            web_directory: string | null
            main_package: string | null
            app_size: string
            runtime_version: string | null
            database: string
            redis: string
            horizon: boolean
        }
    }

    const FRAMEWORKS: Record<string, { value: string; label: string }[]> = $derived({
        php: [
            { value: 'laravel', label: 'Laravel' },
            { value: 'symfony', label: 'Symfony' },
            { value: '', label: 'Plain PHP' }
        ],
        js: [...js_frameworks, { value: '', label: 'Other server' }],
        ruby: [
            { value: 'rails', label: 'Rails' },
            { value: 'sinatra', label: 'Sinatra' },
            { value: 'rack', label: 'Rack' }
        ],
        python: [...python_frameworks, { value: '', label: 'Other framework' }],
        go: go_frameworks,
        java: java_frameworks,
        static: [{ value: '', label: 'No framework' }]
    })

    const GIT_URL = 'url'

    const DEFAULT_BRANCH = 'main'

    const WAIT_MS = 700

    const STACK_FIELDS = ['type', 'framework', 'js_runtime', 'web_directory', 'main_package']

    const UNSET = 'unset'

    const SERVES_FILES = ['php', 'static']

    const STEPS = [
        { name: 'Clone release', desc: 'the latest commit on the branch, with its submodules' },
        { name: 'Install dependencies', desc: "with the package manager the app's lockfile names" },
        { name: 'Build assets', desc: 'when the app has a build script' },
        { name: 'Run migrations', desc: 'for frameworks that have them' },
        { name: 'Health check', desc: 'before anything goes live' },
        { name: 'Go live', desc: 'visitors move to the new release in one step, and the old one is kept for rollback' }
    ]

    let type = $state('php')
    let framework = $derived.by(() => {
        const language = type

        return untrack(() => FRAMEWORKS[language]?.[0]?.value ?? '')
    })
    let typeSelect = $state<HTMLSelectElement | null>(null)
    let frameworkSelect = $state<HTMLSelectElement | null>(null)
    let jsRuntime = $state('bun')
    let webDirectory = $state<string | null>(untrack(() => setup?.application.web_directory ?? null))
    const filesFramework = $derived(
        type === 'js' && js_frameworks.some((choice) => choice.value === framework && choice.files)
    )
    const servesFiles = $derived(SERVES_FILES.includes(type) || filesFramework)
    const webRoot = $derived(webDirectory ?? (filesFramework ? '/dist' : '/public'))
    let mainPackage = $state('')
    let mainPackages = $state<string[]>([])
    const picker = $derived(pickerRuntime(type, jsRuntime))
    let pickedSize = $state<string | null>(untrack(() => setup?.application.app_size ?? null))
    let domainName = $state('')
    const size = $derived(pickedSize ?? (type === 'ruby' || type === 'java' ? 'medium' : 'small'))
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
    let name = $state('')
    let nameEdited = $state(false)
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

    const editing = untrack(() => setup)
    const linked = editing?.application.source.kind === 'github' ? editing.application.source : null

    if (editing) {
        const current = editing.application

        type = current.type
        framework = current.framework ?? ''
        jsRuntime = current.js_runtime ?? 'bun'
        mainPackage = current.main_package ?? ''
        branch = current.branch
        branchEdited = true
        folder = current.directory
        name = current.name
        nameEdited = true
        chosenSource = GIT_URL
        repositoryUrl = current.source.kind === 'url' ? current.source.url : ''
        touched = Object.fromEntries(STACK_FIELDS.map((field) => [field, true]))
    }

    const read = $derived(detection?.state === 'read' ? detection : null)

    const ENGINES: Record<string, string> = { postgresql: 'PostgreSQL', mysql: 'MySQL', redis: 'Redis' }
    let pickedDatabase = $state<string | null>(editing?.application.database ?? null)
    let pickedRedis = $state<string | null>(editing?.application.redis ?? null)
    const databaseOptions = $derived([
        ...(databases.can_create
            ? [
                  { value: 'new:postgresql', label: 'New PostgreSQL database' },
                  { value: 'new:mysql', label: 'New MySQL database' }
              ]
            : []),
        ...databases.choices
            .filter((choice) => choice.type !== 'redis')
            .map((choice) => ({
                value: choice.value,
                label: `${choice.name}, ${ENGINES[choice.type] ?? choice.type}`
            })),
        ...(framework === 'laravel' ? [{ value: 'sqlite', label: 'SQLite, kept across deploys' }] : []),
        { value: 'none', label: "None, I'll set it up myself" }
    ])
    const redisOptions = $derived([
        { value: 'none', label: 'None' },
        ...(databases.can_create ? [{ value: 'new', label: 'New Redis database' }] : []),
        ...databases.choices
            .filter((choice) => choice.type === 'redis')
            .map((choice) => ({ value: choice.value, label: `${choice.name}, Redis` }))
    ])
    const suggestedDatabase = $derived.by(() => {
        if (editing) {
            return 'none'
        }

        const wanted = read?.environment?.database
        const existing = databases.choices.find((choice) => choice.type === wanted)

        if (wanted && databases.can_create) {
            return `new:${wanted}`
        }

        return existing?.value ?? (framework === 'laravel' ? 'sqlite' : 'none')
    })
    const horizonWantsRedis = $derived(!editing && framework === 'laravel' && read?.environment?.horizon === true)
    const suggestedRedis = $derived(
        !editing && (read?.environment?.redis || horizonWantsRedis)
            ? databases.can_create
                ? 'new'
                : (databases.choices.find((choice) => choice.type === 'redis')?.value ?? 'none')
            : 'none'
    )
    const database = $derived(
        pickedDatabase !== null && databaseOptions.some((option) => option.value === pickedDatabase)
            ? pickedDatabase
            : suggestedDatabase
    )
    const redis = $derived(
        pickedRedis !== null && redisOptions.some((option) => option.value === pickedRedis)
            ? pickedRedis
            : suggestedRedis
    )
    const switchNote = $derived.by(() => {
        const before = editing?.application.database ?? 'none'

        if (before === 'none' || database === before) {
            return null
        }

        if (before === 'sqlite') {
            return database === 'none'
                ? 'The app keeps using SQLite until you change DB_CONNECTION in its variables.'
                : "The SQLite database keeps its data. Coresmith doesn't copy it to the database you picked."
        }

        const name = databases.choices.find((choice) => choice.value === before)?.name ?? 'The old database'

        return database === 'none'
            ? `The app's variables still point at ${name} until you change them.`
            : `${name} keeps its data. Coresmith doesn't copy it to the database you picked.`
    })
    const changedStack = $derived(STACK_FIELDS.some((field) => touched[field]))
    const current = $derived<Record<string, string | null>>({
        type,
        framework,
        js_runtime: jsRuntime,
        web_directory: webDirectory,
        main_package: mainPackage
    })
    const readRuntime = $derived(
        read?.fields.type ? pickerRuntime(read.fields.type, read.fields.js_runtime ?? '') : null
    )
    const runtimeAsRead = $derived(read !== null && picker === readRuntime)
    const refused = $derived(
        (read?.verdict === 'blocked' && !changedStack) || (detection?.state === 'failed' && detection.definitive)
    )

    onMount(() => {
        if (editing) {
            detectSoon(0)
        }
    })

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

        if (!nameEdited) {
            name = repositoryName(pick.full_name)
        }

        if (repositoryChanged) {
            leaveFolder()
            detectSoon(0)
        }
    }

    function repositoryName(address: string): string {
        return (
            address
                .trim()
                .replace(/\/+$/, '')
                .replace(/\.git$/, '')
                .split(/[/:]/)
                .pop() ?? ''
        )
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

        if (linked) {
            return {
                source_provider_id: linked.connection_id,
                repository_id: linked.repository_id,
                repository_branch: branch,
                ...directory
            }
        }

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

    function asRead(field: string): boolean {
        if (read === null || (read.fields.type !== null && type !== read.fields.type)) {
            return false
        }

        const detected = (read.fields as Record<string, string | null | undefined>)[field]

        return detected === null || detected === undefined || current[field] === detected
    }

    function touch(field: string) {
        touched = { ...touched, [field]: true }
    }

    function useRead() {
        if (read) {
            touched = {}
            fill(read.fields, read.unsettled)
        }
    }
</script>

{#snippet note(field: string)}
    {#if read && !touched[field] && asRead(field)}
        {#if read.unsettled[field]}
            <p class="mt-1.5 mb-0 text-[12px] text-warning-strong">{read.unsettled[field]}</p>
        {:else if read.sources[field]}
            <p class="mt-1.5 mb-0 text-[12px] wrap-anywhere text-muted-foreground">{read.sources[field]}</p>
        {/if}
    {/if}
{/snippet}

<AppHead title={editing ? `Edit setup · ${editing.application.name}` : 'Add an app'} />

<BackLink href={editing ? editing.back : server.href} label={editing ? editing.application.name : server.name} />

<h1 class="type-h1 m-0 mb-1.5">{editing ? 'Edit setup' : 'Add an app'}</h1>
<p class="type-body-sm mb-7 text-muted-foreground">
    {#if editing}
        <span class="font-mono text-[13px]">{editing.application.name}</span> on
        <span class="font-mono text-[13px]">{server.name}</span>. Changes apply on the next deploy.
    {:else}
        It runs on <span class="font-mono text-[13px]">{server.name}</span>, walled off from the other apps there.
    {/if}
</p>

<div class="grid grid-cols-2 items-start gap-4 max-[900px]:grid-cols-1">
    <div class="rounded-xl border border-border bg-card p-6">
        <Form
            method={editing ? 'put' : 'post'}
            action={editing ? editing.action : route('servers.applications.store', server.id)}
        >
            {#snippet children({ errors, processing })}
                <input type="hidden" name="directory" value={folder} />
                <input type="hidden" name="detection" value={detectionId(detection)} />
                <div class="flex flex-col gap-4">
                    {#if !editing && github.connections.length > 0}
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
                    {#if linked}
                        <div data-testid="setup-repository">
                            <p class="m-0 mb-1.5 text-[14px] font-medium">Repository</p>
                            <p class="m-0 font-mono text-[13px] wrap-anywhere">{linked.full_name}</p>
                            <p class="m-0 mt-1 text-[12px] text-muted-foreground">
                                From GitHub on {linked.account}. To deploy a different repository, add it as a new app.
                            </p>
                        </div>
                    {:else if connection && !editing}
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
                                    if (!nameEdited) {
                                        name = repositoryName(repositoryUrl)
                                    }

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
                    {#if !editing}
                        <div>
                            <Label for="name" class="mb-1.5">Name</Label>
                            <Input
                                id="name"
                                name="name"
                                bind:value={name}
                                oninput={() => (nameEdited = true)}
                                required
                                placeholder="atlas"
                                autocomplete="off"
                                aria-invalid={errors.name ? true : undefined}
                            />
                            <FieldError message={errors.name} />
                        </div>
                    {/if}
                    <StackDetection {detection} {reading} server={server.name} editing={editing !== null} />
                    {#if editing && read}
                        <div>
                            <Button type="button" variant="outline" size="sm" onclick={useRead}
                                >Use what Coresmith read</Button
                            >
                        </div>
                    {/if}
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
                                    { value: 'java', label: 'Java' },
                                    { value: 'static', label: 'Static' }
                                ]}
                                aria-invalid={errors.type ? true : undefined}
                            />
                            {@render note('type')}
                            {#if type === 'static' && read?.builds_with}
                                <p class="mt-1.5 mb-0 text-[12px] text-muted-foreground" data-testid="build-tool">
                                    {read.builds_with.lockfile
                                        ? `Builds with ${read.builds_with.tool}, from ${read.builds_with.lockfile}.`
                                        : `Builds with ${read.builds_with.tool}, since the repository has no lockfile. To build with Bun, commit its bun.lock.`}
                                </p>
                            {/if}
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
                                    ...(FRAMEWORKS[type] ?? []).map((choice) =>
                                        type === 'js' && choice.value === '' && read?.server
                                            ? { ...choice, label: read.server }
                                            : choice
                                    )
                                ]}
                                aria-invalid={errors.framework ? true : undefined}
                            />
                            {#if type === 'js' && framework === '' && !read?.server}
                                <p class="mt-1.5 mb-0 text-[12px] text-muted-foreground" data-testid="server-hint">
                                    Express, Hono, Elysia or any server its start script runs.
                                </p>
                            {/if}
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
                                value={editing?.application.runtime_version ?? null}
                                error={errors.runtime_version}
                                detected={runtimeAsRead ? (read?.sources.runtime_version ?? null) : null}
                            />
                        {/key}
                        {#if runtimeAsRead && read?.unsettled.runtime_version}
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
                    {#if servesFiles}
                        <div>
                            <Label for="web_directory" class="mb-1.5">Web root</Label>
                            <Input
                                id="web_directory"
                                name="web_directory"
                                value={webRoot}
                                oninput={(event) => {
                                    webDirectory = event.currentTarget.value
                                    touch('web_directory')
                                }}
                                required
                                class="font-mono text-[13px]"
                                aria-invalid={errors.web_directory ? true : undefined}
                            />
                            {@render note('web_directory')}
                            <FieldError message={errors.web_directory} />
                        </div>
                    {/if}
                    <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                        {#if !editing}
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
                        {/if}
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
                        {#if !editing}
                            <div class="col-span-full max-sm:order-1">
                                {#if server.dns_targets.length > 0}
                                    <p class="m-0 mb-1 text-[12px] text-muted-foreground">
                                        Point the domain at this server with {server.dns_targets.length === 1
                                            ? 'this record'
                                            : 'these records'}. Coresmith asks for its certificate when the app first
                                        goes live.
                                    </p>
                                    <DnsRecords
                                        records={namedTargets(server.dns_targets, domainName)}
                                        testid="domain-targets"
                                    />
                                {:else}
                                    <p class="m-0 text-[12px] text-muted-foreground">
                                        Point the domain at the server first: an A record for its IPv4 address, or an
                                        AAAA record for IPv6. Once the app is live, Coresmith checks the domain from
                                        outside, shows the record it needs and asks for its certificate.
                                    </p>
                                {/if}
                                {#if server.network}
                                    <div
                                        class="tint-warning mt-2.5 flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5"
                                        data-testid="add-app-network"
                                    >
                                        <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                                        <p class="m-0 min-w-0 flex-1 text-[12.5px]">
                                            {server.network.text}
                                            <TextLink href={server.network.href} target="_blank" rel="noopener"
                                                >{server.network.action}<span class="sr-only">
                                                    (opens in a new tab)</span
                                                ></TextLink
                                            >
                                        </p>
                                    </div>
                                {/if}
                            </div>
                        {/if}
                    </div>
                    <div class="grid gap-3" data-testid="app-databases">
                        <div>
                            <Label for="database" class="mb-1.5">Database</Label>
                            <NativeSelect
                                id="database"
                                name="database"
                                bind:value={() => database, (value: string) => (pickedDatabase = value)}
                                options={databaseOptions}
                                aria-invalid={errors.database ? true : undefined}
                            />
                            {#if switchNote}
                                <p
                                    class="mt-1.5 mb-0 text-[12px] text-warning-strong"
                                    data-testid="database-switch-note"
                                >
                                    {switchNote}
                                </p>
                            {:else if !editing && read?.environment?.database && pickedDatabase === null}
                                <p class="mt-1.5 mb-0 text-[12px] text-muted-foreground">
                                    Picked from DB_CONNECTION in {read.environment.example}.
                                </p>
                            {:else if !editing && read && !read.environment?.example}
                                <p class="mt-1.5 mb-0 text-[12px] text-muted-foreground" data-testid="env-example-hint">
                                    Add a .env.example to your repository and Coresmith starts the app's variables from
                                    it.
                                </p>
                            {/if}
                            <FieldError message={errors.database} />
                        </div>
                        <div>
                            <Label for="redis" class="mb-1.5">Redis</Label>
                            <NativeSelect
                                id="redis"
                                name="redis"
                                bind:value={() => redis, (value: string) => (pickedRedis = value)}
                                options={redisOptions}
                                aria-invalid={errors.redis ? true : undefined}
                            />
                            {#if !editing && read?.environment?.redis && pickedRedis === null}
                                <p class="mt-1.5 mb-0 text-[12px] text-muted-foreground">
                                    Picked because {read.environment.example} uses Redis.
                                </p>
                            {:else if horizonWantsRedis && pickedRedis === null}
                                <p
                                    class="mt-1.5 mb-0 text-[12px] text-muted-foreground"
                                    data-testid="horizon-redis-note"
                                >
                                    Picked because Horizon needs Redis for the queue.
                                </p>
                            {:else if editing?.application.horizon && redis === 'none'}
                                <p class="mt-1.5 mb-0 text-[12px] text-warning-strong" data-testid="horizon-redis-note">
                                    Horizon needs Redis, so the queue worker can't start without one.
                                </p>
                            {/if}
                            <FieldError message={errors.redis} />
                        </div>
                    </div>
                    {#if !editing}
                        <ProjectSelect {projects} value={project} error={errors.project_id} />
                        <div>
                            <Label for="tags" class="mb-1.5">Tags</Label>
                            <TagInput
                                id="tags"
                                name="tags"
                                suggestions={tag_suggestions}
                                error={firstTagError(errors)}
                                describedby="tags-hint"
                            />
                            <p id="tags-hint" class="m-0 mt-1.5 text-[12px] text-muted-foreground">
                                Short labels such as production or a client's name, so apps with the same name are easy
                                to tell apart. Press Enter after each one, up to 5.
                            </p>
                        </div>
                    {/if}
                    <Button type="submit" class="w-full" disabled={processing || refused}
                        >{editing ? 'Save setup' : 'Add the app'}</Button
                    >
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
