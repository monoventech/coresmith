import type { Page } from '@playwright/test'
import {
    backToMonoven,
    blockingViolations,
    dialog,
    escape,
    expect,
    own,
    tabs,
    test,
    toast,
    useTheme
} from './support/fixtures'

async function openWithEnvironmentApp(page: Page): Promise<string> {
    const baseURL = test.info().project.use.baseURL ?? 'http://127.0.0.1:8123'
    const connect = `${baseURL}/acme/settings/source-providers/github_app/connect?app=env`

    await page.route('**/acme/settings/git', async (route) => {
        if (route.request().headers()['x-inertia'] === undefined) {
            await route.continue()

            return
        }

        const response = await route.fetch()
        const body = await response.json()

        body.props.github = {
            ...body.props.github,
            can_register: false,
            registration: null,
            apps: [],
            connect: { install_href: connect, link_href: `${connect}&mode=link` }
        }
        await route.fulfill({ response, json: body })
    })
    await page.goto('/acme/settings')
    await tabs(page, 'Settings').getByRole('link', { name: 'Git', exact: true }).click()
    await page.getByTestId('github-connect').waitFor()

    return connect
}

test.afterEach(async ({ page }) => {
    await backToMonoven(page)
})

test.describe('creating a GitHub App from the panel', () => {
    test("a team without a GitHub App is offered one with this panel's addresses filled in", async ({ page }) => {
        const baseURL = test.info().project.use.baseURL ?? 'http://127.0.0.1:8123'
        const captured = { action: '', body: '' }

        await page.route('https://github.com/organizations/**', async (route) => {
            captured.action = route.request().url()
            captured.body = route.request().postData() ?? ''
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })
        await page.goto('/acme/settings/git')
        await page.getByRole('radio', { name: 'An organization' }).check()
        await page.getByLabel('Organization', { exact: true }).fill('acme-labs')
        await page.getByRole('button', { name: 'Create on GitHub' }).click()

        await expect
            .poll(() => captured.action)
            .toMatch(/^https:\/\/github\.com\/organizations\/acme-labs\/settings\/apps\/new\?state=[A-Za-z0-9]{40}$/)

        const fields = new URLSearchParams(captured.body)
        const manifest = JSON.parse(fields.get('manifest') ?? '{}')

        expect([...fields.keys()]).toEqual(['manifest'])
        expect(manifest.redirect_url).toBe(`${baseURL}/settings/git/github/registered`)
        expect(manifest.callback_urls).toEqual([`${baseURL}/settings/source-providers/github_app/callback`])
        expect(manifest.default_permissions).toEqual({ contents: 'read', metadata: 'read', statuses: 'write' })
        expect(manifest.public).toBe(false)
        expect(manifest.request_oauth_on_install).toBe(true)
        expect(manifest.name).toBe('Coresmith Acme Labs')
        expect(manifest.hook_attributes).toBeUndefined()
    })

    test('the personal account posts to the personal app page', async ({ page }) => {
        const captured = { action: '', body: '' }

        await page.route('https://github.com/settings/apps/new**', async (route) => {
            captured.action = route.request().url()
            captured.body = route.request().postData() ?? ''
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })
        await page.goto('/acme/settings/git')

        await expect(page.getByRole('radio', { name: 'Your personal account' })).toBeChecked()
        await expect(page.getByLabel('Organization', { exact: true })).toHaveCount(0)

        await page.getByLabel('Name on GitHub').fill('Coresmith Acme')
        await page.getByRole('button', { name: 'Create on GitHub' }).click()

        await expect
            .poll(() => captured.action)
            .toMatch(/^https:\/\/github\.com\/settings\/apps\/new\?state=[A-Za-z0-9]{40}$/)

        const manifest = JSON.parse(new URLSearchParams(captured.body).get('manifest') ?? '{}')

        expect(manifest.name).toBe('Coresmith Acme')
        expect(manifest.hook_attributes).toBeUndefined()
    })

    test('an organization name GitHub would refuse is caught before leaving', async ({ page }) => {
        await page.goto('/acme/settings/git')
        await page.getByRole('radio', { name: 'An organization' }).check()

        const organization = page.getByLabel('Organization', { exact: true })

        await organization.fill('acme labs')

        await expect(organization).toHaveAttribute('aria-invalid', 'true')
        await expect(page.getByTestId('github-registration')).toContainText(
            "That isn't a GitHub organization name. Use letters, numbers and single hyphens."
        )
        await expect(page.getByRole('button', { name: 'Create on GitHub' })).toBeDisabled()

        await organization.fill('acme-labs')

        await expect(organization).not.toHaveAttribute('aria-invalid', 'true')
        await expect(page.getByRole('button', { name: 'Create on GitHub' })).toBeEnabled()
    })

    test('an app name GitHub would refuse is caught before leaving', async ({ page }) => {
        await page.goto('/acme/settings/git')

        const name = page.getByLabel('Name on GitHub')

        await expect(name).toHaveValue('Coresmith Acme Labs')

        await name.fill('Coresmith/Acme')

        await expect(name).toHaveAttribute('aria-invalid', 'true')
        await expect(page.getByTestId('github-registration')).toContainText(
            'Use letters, numbers, spaces and hyphens, up to 34 characters.'
        )
        await expect(page.getByRole('button', { name: 'Create on GitHub' })).toBeDisabled()
    })

    test('the panel says why GitHub cannot reach it and can register a webhook anyway', async ({ page }) => {
        const baseURL = test.info().project.use.baseURL ?? 'http://127.0.0.1:8123'
        const captured = { body: '' }

        await page.route('https://github.com/settings/apps/new**', async (route) => {
            captured.body = route.request().postData() ?? ''
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })
        await page.goto('/acme/settings/git')

        await expect(page.getByTestId('github-registration')).toContainText(
            'GitHub cannot reach 127.0.0.1, it only answers on this machine. Apps with auto deploy check their branch every minute instead.'
        )

        await page
            .getByRole('checkbox', { name: 'Register a webhook anyway, 127.0.0.1 is reachable from the internet' })
            .check()
        await page.getByRole('button', { name: 'Create on GitHub' }).click()

        await expect.poll(() => captured.body).not.toBe('')

        const manifest = JSON.parse(new URLSearchParams(captured.body).get('manifest') ?? '{}')

        expect(manifest.hook_attributes.url).toMatch(
            new RegExp(`^${escape(baseURL)}/api/webhooks/github/[0-9a-z]{26}$`)
        )
        expect(manifest.hook_attributes.active).toBe(true)
        expect(manifest.default_events).toEqual(['push'])
    })
})

test.describe('a GitHub App registered but not installed', () => {
    test('says who can install it and offers both ways to connect from its own card', async ({ page }, testInfo) => {
        const lab = own(testInfo, 'lab')
        const captured = { location: '', navigation: false }

        await page.route('**/settings/source-providers/github_app/connect**', async (route) => {
            const response = await route.fetch({ maxRedirects: 0 })

            captured.location = response.headers()['location'] ?? ''
            captured.navigation = route.request().isNavigationRequest()
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })
        await page.goto(`/${lab}/settings/git`)

        const card = page.getByTestId('github-app-card')
        const install = card.getByRole('link', { name: 'Install on GitHub' })
        const link = card.getByRole('link', { name: 'Link an existing installation' })

        await expect(card).toContainText('Not installed')
        await expect(card).toContainText(
            `Coresmith ${lab} is registered on GitHub under ${lab}. Install it on the account that holds your repositories.`
        )
        await expect(card).toContainText(`While the app is private, only ${lab} can install it.`)
        await expect(install).toHaveAttribute(
            'href',
            new RegExp(`/${lab}/settings/source-providers/github_app/connect\\?app=[0-9a-z]{26}$`)
        )
        await expect(install).toHaveAttribute('target', '_self')
        await expect(link).toHaveAttribute(
            'href',
            new RegExp(`/${lab}/settings/source-providers/github_app/connect\\?app=[0-9a-z]{26}&mode=link$`)
        )
        await expect(link).toHaveAttribute('target', '_self')
        await expect(page.getByRole('link', { name: 'Install on GitHub' })).toHaveCount(1)
        await expect(page.getByTestId('github-connect')).toHaveCount(0)
        await expect(page.getByTestId('webhooks-card')).toContainText(
            'GitHub cannot reach 127.0.0.1, it only answers on this machine.'
        )
        await expect(
            page.getByTestId('webhooks-card').getByRole('button', { name: 'Point the webhook here' })
        ).toBeVisible()

        await link.click()

        await expect.poll(() => captured.location).toMatch(/^https:\/\/github\.com\/login\/oauth\/authorize\?/)
        expect(captured.navigation).toBe(true)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`it has no serious or critical accessibility violations in ${theme}`, async ({ page }, testInfo) => {
            await useTheme(page, theme)
            await page.goto(`/${own(testInfo, 'lab')}/settings/git`)
            await page.getByTestId('github-app-card').waitFor()

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('removing it asks first, can be called off, then forgets it', async ({ page }, testInfo) => {
        const lab = own(testInfo, 'lab')
        const card = page.getByTestId('github-app-card')

        await page.goto(`/${lab}/settings/git`)
        await card.getByRole('button', { name: 'Remove' }).click()

        await expect(dialog(page)).toContainText(`Remove Coresmith ${lab} from Coresmith?`)
        await expect(dialog(page)).toContainText(
            "Coresmith forgets the app's key and secrets. The app stays on GitHub until you delete it in its settings there."
        )
        await dialog(page).getByRole('button', { name: 'Keep it' }).click()

        await expect(dialog(page)).toHaveCount(0)
        await expect(card).toBeVisible()

        await card.getByRole('button', { name: 'Remove' }).click()
        await dialog(page).getByRole('button', { name: 'Remove the app' }).click()

        await expect(toast(page)).toContainText('Removed.')
        await expect(toast(page)).toContainText('Delete the app on GitHub to finish.')
        await expect(card).toHaveCount(0)
        await expect(page.getByTestId('github-registration')).toBeVisible()
    })
})

test.describe('a suspended installation', () => {
    test('says GitHub suspended it and what to do about it', async ({ page }) => {
        await page.goto('/paused/settings/git')

        const card = page.getByTestId('provider-card')

        await expect(card).toContainText('Suspended')
        await expect(card).toContainText(
            'Installed on paused-works · all repositories · contents read-only, commit statuses'
        )
        await expect(card).toContainText(
            'GitHub has suspended this installation. Unsuspend it on GitHub to deploy again.'
        )
        await expect(card).not.toContainText('Installation token expires')
    })
})

test.describe('a team on its own domain', () => {
    const origin = process.env.APP_URL ?? `http://127.0.0.1:${process.env.E2E_PORT ?? 8123}`

    test.use({ proxy: { server: origin } })

    test('creating the GitHub App is sent to the panel address', async ({ page, context }) => {
        const cookies = await context.cookies(origin)
        const host = new URL(origin).host

        await context.addCookies(
            cookies.map(({ name, value }) => ({ name, value, domain: 'panel.northwind.test', path: '/' }))
        )
        await page.goto('http://panel.northwind.test/settings/git')

        const card = page.getByTestId('github-elsewhere')

        await expect(card).toContainText(`Create the app from ${host}, GitHub sends you back there.`)
        await expect(card.getByRole('link', { name: `Open ${host}` })).toHaveAttribute(
            'href',
            `${origin}/northwind/settings/git`
        )
        await expect(page.getByTestId('github-registration')).toHaveCount(0)
    })
})

test.describe('with the environment GitHub App', () => {
    test('a team with nothing connected is offered both ways to connect as real navigations', async ({ page }) => {
        const connect = await openWithEnvironmentApp(page)
        const card = page.getByTestId('github-connect')
        const captured = { url: '', navigation: false }

        await page.route('**/settings/source-providers/github_app/connect**', async (route) => {
            captured.url = route.request().url()
            captured.navigation = route.request().isNavigationRequest()
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })

        await expect(card).toContainText(
            'Install the Coresmith GitHub App on your organisation. It asks for the minimum: contents read-only, commit statuses and webhooks, per repository, not your whole account.'
        )
        await expect(card.getByRole('link', { name: 'Install on GitHub' })).toHaveAttribute('href', connect)
        await expect(card.getByRole('link', { name: 'Install on GitHub' })).toHaveAttribute('target', '_self')
        await expect(card.getByRole('link', { name: 'Link an existing installation' })).toHaveAttribute(
            'href',
            `${connect}&mode=link`
        )
        await expect(card.getByRole('link', { name: 'Link an existing installation' })).toHaveAttribute(
            'target',
            '_self'
        )
        await expect(page.getByTestId('github-registration')).toHaveCount(0)

        await card.getByRole('link', { name: 'Install on GitHub' }).click()

        await expect.poll(() => captured.url).toBe(connect)
        await expect(page).toHaveTitle('stub')
        expect(captured.navigation).toBe(true)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`it has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await openWithEnvironmentApp(page)

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

test.describe('other Git providers', () => {
    test('GitLab connects through the panel as a real navigation', async ({ page }) => {
        const captured = { location: '', navigation: false }

        await page.route('**/settings/source-providers/gitlab/connect**', async (route) => {
            const response = await route.fetch({ maxRedirects: 0 })

            captured.location = response.headers()['location'] ?? ''
            captured.navigation = route.request().isNavigationRequest()
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })
        await page.goto('/acme/settings/git')

        const connect = page.getByTestId('connect-card').getByRole('link', { name: 'Connect GitLab' })

        await expect(connect).toHaveAttribute('target', '_self')

        await connect.click()

        await expect.poll(() => captured.location).toMatch(/^https:\/\/gitlab\.com\/oauth\/authorize\?/)
        await expect(page).toHaveTitle('stub')
        expect(captured.navigation).toBe(true)
    })
})
