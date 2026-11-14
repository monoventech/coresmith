import type { Page } from '@playwright/test'
import { blockingViolations, expect, gotoServer, REPOSITORY_READS, test, useTheme } from './support/fixtures'

const COMMIT = '9f3c2e1a4b5c6d7e8f9012345678901234567890'

const READY = {
    id: 'k7vq2mz9xw4hpbn6tq8rjf3ylc5ds0ga',
    state: 'read',
    commit: COMMIT,
    verdict: 'ready',
    fields: { type: 'php', framework: 'laravel', js_runtime: null, web_directory: '/public', version: '8.4.25' },
    sources: {
        type: 'From composer.json',
        framework: 'laravel/framework 12.38.1 in composer.lock',
        web_directory: 'Laravel serves public/index.php',
        runtime_version: 'PHP 8.4.25, the newest your composer.lock allows: nette/schema 1.3.2 stops at 8.4'
    },
    unsettled: {},
    blockers: []
}

const UNSURE = {
    id: 'p2wq8mz9xw4hpbn6tq8rjf3ylc5ds0gb',
    state: 'read',
    commit: COMMIT,
    verdict: 'unsure',
    fields: { type: 'ruby', framework: null, js_runtime: null, web_directory: null, version: '3.4.7' },
    sources: { type: 'From Gemfile and Gemfile.lock', runtime_version: 'Ruby 3.4.7 from .ruby-version' },
    unsettled: {
        framework:
            "Gemfile names neither rails nor sinatra, and there is no config.ru, so Coresmith can't tell how to start the app. Add a config.ru that runs it."
    },
    blockers: []
}

const MONOREPO = {
    id: 'm4wq8mz9xw4hpbn6tq8rjf3ylc5ds0gf',
    state: 'read',
    commit: COMMIT,
    verdict: 'unsure',
    fields: { type: null, framework: null, js_runtime: null, web_directory: null, version: null },
    sources: {},
    unsettled: {
        type: "There is no composer.json, Gemfile, requirements.txt, pyproject.toml, package.json, deno.json or index.html at the repository's root, so Coresmith can't tell what the app runs on. Pick the folder the app is in."
    },
    blockers: [],
    folders: ['apps/api', 'apps/dashboard']
}

const DASHBOARD = { ...READY, id: 'm5wq8mz9xw4hpbn6tq8rjf3ylc5ds0gh', folders: ['apps/api', 'apps/dashboard'] }

const UNREAD = {
    id: 't6wq8mz9xw4hpbn6tq8rjf3ylc5ds0ge',
    state: 'read',
    commit: COMMIT,
    verdict: 'unsure',
    fields: { type: null, framework: null, js_runtime: null, web_directory: null, version: null },
    sources: {},
    unsettled: {
        type: "There is no composer.json, Gemfile, requirements.txt, pyproject.toml, package.json, deno.json or index.html at the repository's root, so Coresmith can't tell what the app runs on."
    },
    blockers: []
}

const BLOCKED = {
    id: 'b4wq8mz9xw4hpbn6tq8rjf3ylc5ds0gc',
    state: 'read',
    commit: COMMIT,
    verdict: 'blocked',
    fields: { type: 'js', framework: 'sveltekit', js_runtime: 'node', web_directory: null, version: '20.20.2' },
    sources: {
        type: 'From package.json',
        js_runtime: 'From pnpm-lock.yaml',
        framework: '@sveltejs/kit with @sveltejs/adapter-vercel',
        runtime_version: 'Node.js 20.20.2 from .nvmrc (20)'
    },
    unsettled: {},
    blockers: [
        {
            field: 'framework',
            reason: "svelte.config.js uses @sveltejs/adapter-vercel, which builds the app for that hosting platform's own servers.",
            fix: 'Install @sveltejs/adapter-node, use it in svelte.config.js, then commit both.'
        }
    ]
}

async function answer(page: Page, answers: object[]): Promise<string[]> {
    const asked: string[] = []

    await page.route(REPOSITORY_READS, async (route) => {
        asked.push(route.request().url())
        await route.fulfill({ json: answers[Math.min(asked.length, answers.length) - 1] })
    })

    return asked
}

async function openAddApp(page: Page) {
    await gotoServer(page, 'bench-01')
    await page.getByRole('link', { name: 'Add an app' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
    await page.getByLabel('Source', { exact: true }).selectOption({ label: 'A Git URL' })
}

function detection(page: Page) {
    return page.getByTestId('stack-detection')
}

test.describe('Stack detection on Add an app', () => {
    test('a repository Coresmith reads fills in its stack and says where each value came from', async ({ page }) => {
        const asked = await answer(page, [READY])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/shop.git')

        await expect(detection(page)).toContainText('Filled in from the repository at 9f3c2e1.')
        await expect(page.getByLabel('Language')).toHaveValue('php')
        await expect(page.getByLabel('Framework')).toHaveValue('laravel')
        await expect(page.getByLabel('Web root')).toHaveValue('/public')
        await expect(page.getByText('laravel/framework 12.38.1 in composer.lock')).toBeVisible()
        await expect(page.getByText('Laravel serves public/index.php')).toBeVisible()
        await expect(
            page.getByRole('radiogroup', { name: 'PHP version' }).getByRole('radio', { name: /From the repository/ })
        ).toHaveAccessibleName(/PHP 8\.4\.25, the newest your composer\.lock allows/)
        await expect(page.locator('input[name="detection"]')).toHaveValue(READY.id)
        expect(asked).toHaveLength(1)
        expect(new URL(asked[0]).searchParams.get('repository_url')).toBe('https://github.com/acme/shop.git')
        expect(new URL(asked[0]).searchParams.get('repository_branch')).toBe('main')
    })

    test('a field the files do not settle is left for the person, with the reason', async ({ page }) => {
        await answer(page, [UNSURE])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/worker.git')

        await expect(detection(page)).toContainText('Filled in what the repository settles.')
        await expect(page.getByLabel('Language')).toHaveValue('ruby')
        await expect(
            page.getByText('Gemfile names neither rails nor sinatra, and there is no config.ru', { exact: false })
        ).toBeVisible()
        await expect(page.getByRole('button', { name: 'Add the app' })).toBeEnabled()
    })

    test('a framework the files leave open must be picked before the app can be added', async ({ page }) => {
        await answer(page, [UNSURE])

        await openAddApp(page)
        await page.getByLabel('Name', { exact: true }).fill('worker')
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/worker.git')
        await expect(detection(page)).toContainText('Filled in what the repository settles.')

        const framework = page.getByLabel('Framework')

        await expect(framework).toHaveValue('unset')
        await expect(framework.locator('option:checked')).toHaveText('Pick one')

        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
        expect(await framework.evaluate((select: HTMLSelectElement) => select.validationMessage)).toBe(
            'Pick a framework.'
        )

        await framework.selectOption('sinatra')

        expect(await framework.evaluate((select: HTMLSelectElement) => select.validity.valid)).toBe(true)
    })

    test('a language the files leave open must be picked before the app can be added', async ({ page }) => {
        await answer(page, [UNREAD])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/notes.git')
        await expect(detection(page)).toContainText('Filled in what the repository settles.')

        const language = page.getByLabel('Language')

        await expect(language).toHaveValue('unset')
        await expect(language.locator('option:checked')).toHaveText('Pick one')
        expect(await language.evaluate((select: HTMLSelectElement) => select.validationMessage)).toBe(
            'Pick a language.'
        )

        await language.selectOption('ruby')

        expect(await language.evaluate((select: HTMLSelectElement) => select.validity.valid)).toBe(true)
        await expect(page.getByLabel('Framework')).toHaveValue('rails')
    })

    test('a repository that cannot deploy as is says why and how, and cannot be added until it changes', async ({
        page
    }) => {
        await answer(page, [BLOCKED])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/storefront.git')

        await expect(detection(page)).toContainText("This repository can't deploy as is")
        await expect(detection(page)).toContainText(
            'own servers. Install @sveltejs/adapter-node, use it in svelte.config.js, then commit both.'
        )
        await expect(page.getByRole('button', { name: 'Add the app' })).toBeDisabled()
    })

    test('a git@ repository is read once the app has its deploy key, so the form says so and asks nothing', async ({
        page
    }) => {
        const asked = await answer(page, [READY])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('git@github.com:acme/shop.git')

        await expect(detection(page)).toContainText(
            "Coresmith reads git@ repositories with this app's deploy key, which it makes when you add the app."
        )
        expect(asked).toHaveLength(0)
    })

    test('a server that is not ready says the check waits for it', async ({ page }) => {
        await answer(page, [{ state: 'server_not_ready', server: 'bench-01' }])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/shop.git')

        await expect(detection(page)).toContainText(
            'Coresmith reads the repository once bench-01 is ready. You can add the app now and check it before the first deploy.'
        )
    })

    test('a field changed by hand stays when the repository is read again', async ({ page }) => {
        const asked = await answer(page, [READY, READY])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/shop.git')
        await expect(detection(page)).toContainText('Filled in from the repository')

        await page.getByLabel('Web root').fill('/web')
        await page.getByLabel('Branch', { exact: true }).fill('release')

        await expect.poll(() => asked.length).toBe(2)
        await expect(detection(page)).toContainText('Filled in from the repository')
        await expect(page.getByLabel('Web root')).toHaveValue('/web')
        await expect(page.getByLabel('Framework')).toHaveValue('laravel')
    })

    test('a repository with apps in folders offers them, and the folder picked is the one read and added', async ({
        page
    }) => {
        const asked = await answer(page, [MONOREPO, DASHBOARD])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/mono.git')

        const folder = page.getByLabel('Folder')

        await expect(page.getByText('Pick the folder the app is in.', { exact: false })).toBeVisible()
        await expect(folder).toHaveValue('/')
        await folder.selectOption('apps/dashboard')

        await expect.poll(() => asked.length).toBe(2)
        await expect(page.getByLabel('Language')).toHaveValue('php')
        await expect(page.getByLabel('Framework')).toHaveValue('laravel')
        await expect(page.locator('input[name="directory"]')).toHaveValue('apps/dashboard')
        expect(new URL(asked[1]).searchParams.get('directory')).toBe('apps/dashboard')
    })

    test('a repository whose app is at its root has no folder to pick', async ({ page }) => {
        await answer(page, [READY])

        await openAddApp(page)
        await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/shop.git')

        await expect(detection(page)).toContainText('Filled in')
        await expect(page.getByLabel('Folder')).toHaveCount(0)
        await expect(page.locator('input[name="directory"]')).toHaveValue('/')
    })

    for (const [label, state] of [
        ['fills in', READY],
        ['leaves a field open', UNSURE],
        ['refuses', BLOCKED],
        ['offers folders', MONOREPO]
    ] as const) {
        test(`the form passes axe in light and dark while it ${label}`, async ({ page }) => {
            for (const theme of ['light', 'dark'] as const) {
                await page.unrouteAll({ behavior: 'ignoreErrors' })
                await answer(page, [state])
                await useTheme(page, theme)
                await openAddApp(page)
                await page.getByLabel('Repository', { exact: true }).fill('https://github.com/acme/shop.git')
                await expect(detection(page)).toContainText(state === BLOCKED ? "can't deploy as is" : 'Filled in')

                expect(await blockingViolations(page)).toEqual([])
            }
        })
    }
})
