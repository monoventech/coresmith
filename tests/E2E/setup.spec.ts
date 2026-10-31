import type { Page } from '@playwright/test'
import { backToMonoven, blockingViolations, expect, openSetupWith, test, useTheme } from './support/fixtures'

async function openWithEnvironmentApp(page: Page): Promise<string> {
    const baseURL = test.info().project.use.baseURL ?? 'http://127.0.0.1:8123'
    const connect = `${baseURL}/acme/settings/source-providers/github_app/connect?app=env`

    await openSetupWith(page, 'acme', { connect_href: connect, registration: null })
    await page.getByTestId('setup-step').getByRole('link', { name: 'Connect GitHub' }).waitFor()

    return connect
}

test.describe('setup', () => {
    test('a finished setup stays reachable for reference', async ({ page }) => {
        await page.goto('/setup')

        await expect(page.getByRole('heading', { level: 1, name: 'Set up Coresmith' })).toBeVisible()
        await expect(page.getByRole('main')).toContainText('Every step is done.')

        const steps = page.getByRole('navigation', { name: 'Setup steps' })

        await expect(steps.getByRole('button')).toHaveCount(4)
        await expect(steps.getByRole('button', { name: /First deploy/ })).toHaveAttribute('aria-current', 'step')
        await expect(page.getByTestId('setup-step')).toContainText('Deploy your first app')
    })

    test('an earlier step can be revisited', async ({ page }) => {
        await page.goto('/setup')

        await page
            .getByRole('navigation', { name: 'Setup steps' })
            .getByRole('button', { name: /Connect GitHub/ })
            .click()

        await expect(page.getByTestId('setup-step')).toContainText('Connect GitHub')
        await expect(page.getByTestId('setup-step')).toContainText('Connected as monoventech')
    })

    test('the bullets say how clones are protected', async ({ page }) => {
        await page.goto('/setup?step=0')

        const step = page.getByTestId('setup-step')

        await expect(step).toContainText(
            'Install the Coresmith GitHub App on your organisation. It asks for the minimum: contents read-only, commit statuses and webhooks, per repository, not your whole account.'
        )
        await expect(step.getByText('Fine-grained, per-repository permissions')).toBeVisible()
        await expect(step.getByText('Installation tokens expire after one hour')).toBeVisible()
        await expect(step.getByText('Clones use a one-hour, read-only token for one repository')).toBeVisible()
        await expect(step).not.toContainText('deploy key')

        const checks = step.locator('li svg')

        await expect(checks).toHaveCount(3)

        for (const check of await checks.all()) {
            expect((await check.boundingBox())?.width).toBe(16)
        }
    })

    test('provider provisioning is shown as not available yet, with the reason', async ({ page }) => {
        await page.goto('/setup?step=1')

        await expect(page.getByTestId('setup-step')).toContainText('Arrives with provider integrations.')
        await expect(page.getByTestId('setup-step')).toContainText('Use an existing VM')
    })
})

test.describe('setup before GitHub is connected', () => {
    test.afterEach(async ({ page }) => {
        await backToMonoven(page)
    })

    test('setup offers to create the GitHub App when the team has none', async ({ page }) => {
        const captured = { action: '', body: '' }

        await page.route('https://github.com/settings/apps/new**', async (route) => {
            captured.action = route.request().url()
            captured.body = route.request().postData() ?? ''
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })
        await page.goto('/acme/setup')

        const step = page.getByTestId('setup-step')

        await expect(step.getByRole('radio', { name: 'Your personal account' })).toBeChecked()
        await expect(step.getByLabel('Organization', { exact: true })).toHaveCount(0)
        await expect(step.getByLabel('Name on GitHub')).toHaveCount(0)
        await expect(step.getByRole('checkbox')).toHaveCount(0)

        await step.getByRole('button', { name: 'Connect GitHub' }).click()

        await expect
            .poll(() => captured.action)
            .toMatch(/^https:\/\/github\.com\/settings\/apps\/new\?state=[A-Za-z0-9]{40}$/)

        const fields = new URLSearchParams(captured.body)
        const manifest = JSON.parse(fields.get('manifest') ?? '{}')

        expect([...fields.keys()]).toEqual(['manifest'])
        expect(manifest.callback_urls[0]).toMatch(/\/settings\/source-providers\/github_app\/callback$/)
        expect(manifest.name).toBe('Coresmith Acme Labs')
        expect(manifest.hook_attributes).toBeUndefined()
    })

    test('an organization is named before setup leaves for GitHub', async ({ page }) => {
        const captured = { action: '' }

        await page.route('https://github.com/organizations/**', async (route) => {
            captured.action = route.request().url()
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })
        await page.goto('/acme/setup')

        const step = page.getByTestId('setup-step')
        const submit = step.getByRole('button', { name: 'Connect GitHub' })

        await step.getByRole('radio', { name: 'An organization' }).check()

        const organization = step.getByLabel('Organization', { exact: true })

        await organization.fill('acme labs')

        await expect(organization).toHaveAttribute('aria-invalid', 'true')
        await expect(step).toContainText(
            "That isn't a GitHub organization name. Use letters, numbers and single hyphens."
        )
        await expect(submit).toBeDisabled()

        await organization.fill('acme-labs')
        await submit.click()

        await expect
            .poll(() => captured.action)
            .toMatch(/^https:\/\/github\.com\/organizations\/acme-labs\/settings\/apps\/new\?state=[A-Za-z0-9]{40}$/)
    })

    test('an app to install is connected through the panel as a real navigation', async ({ page }) => {
        const connect = await openWithEnvironmentApp(page)
        const step = page.getByTestId('setup-step')
        const link = step.getByRole('link', { name: 'Connect GitHub' })
        const captured = { url: '', navigation: false }

        await page.route('**/settings/source-providers/github_app/connect**', async (route) => {
            captured.url = route.request().url()
            captured.navigation = route.request().isNavigationRequest()
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })

        await expect(link).toHaveAttribute('href', connect)
        await expect(link).toHaveAttribute('target', '_self')
        await expect(step.getByTestId('github-registration')).toHaveCount(0)

        await link.click()

        await expect.poll(() => captured.url).toBe(connect)
        expect(captured.navigation).toBe(true)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`an app to install has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await openWithEnvironmentApp(page)

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('a team on GitLab alone moves on from the first step', async ({ page }) => {
        await page.goto('/tidewater/setup?step=0')

        const step = page.getByTestId('setup-step')

        await expect(step.getByRole('button', { name: 'Connect GitHub' })).toBeVisible()

        await step.getByRole('button', { name: 'Continue' }).click()

        await expect(page.getByTestId('setup-step')).toContainText('Add your first server')
        await expect(
            page.getByRole('navigation', { name: 'Setup steps' }).getByRole('button', { name: /Add a server/ })
        ).toHaveAttribute('aria-current', 'step')
    })

    test('a member is told who connects GitHub', async ({ page }) => {
        await openSetupWith(page, 'acme', {
            can_manage: false,
            connect_href: null,
            registration: null,
            elsewhere: null
        })

        const step = page.getByTestId('setup-step')

        await expect(step).toContainText('An owner or admin connects GitHub.')
        await expect(step.getByRole('link', { name: 'Connect GitHub' })).toHaveCount(0)
        await expect(step.getByRole('button', { name: 'Connect GitHub' })).toHaveCount(0)
    })
})

test.describe("setup on a team's own domain", () => {
    const origin = process.env.APP_URL ?? `http://127.0.0.1:${process.env.E2E_PORT ?? 8123}`

    test.use({ proxy: { server: origin } })

    test.afterEach(async ({ page }) => {
        await backToMonoven(page)
    })

    test('creating the GitHub App is sent to the panel address', async ({ page, context }) => {
        const cookies = await context.cookies(origin)
        const host = new URL(origin).host

        await context.addCookies(
            cookies.map(({ name, value }) => ({ name, value, domain: 'panel.northwind.test', path: '/' }))
        )
        await page.goto('http://panel.northwind.test/setup')

        const step = page.getByTestId('setup-step')

        await expect(step).toContainText(`Create the app from ${host}, GitHub sends you back there.`)
        await expect(step.getByRole('link', { name: `Open ${host}` })).toHaveAttribute(
            'href',
            `${origin}/northwind/settings/git`
        )
        await expect(step.getByTestId('github-registration')).toHaveCount(0)
        await expect(step).not.toContainText('An owner or admin connects GitHub.')
    })
})
