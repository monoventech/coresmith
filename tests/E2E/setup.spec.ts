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
            'Install the Coresmith GitHub App on your account or organization, on the repositories you choose. It asks only for read-only contents, commit statuses and webhooks.'
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
})

test.describe('the first server in setup', () => {
    test.afterEach(async ({ page }) => {
        await backToMonoven(page)
    })

    test('the first server can come from a provider or an existing vm', async ({ page }) => {
        await page.goto('/foundry/setup?step=1')

        await expect(page.getByRole('button', { name: /^Provision through a provider/ })).toHaveAttribute(
            'aria-pressed',
            'true'
        )
        await expect(page.getByLabel('Provider')).toBeVisible()
        await expect(page.getByLabel('API token')).toBeVisible()

        await page.getByRole('button', { name: /^Use an existing VM/ }).click()

        await expect(page.getByLabel('IP address')).toBeVisible()
        await expect(page.getByLabel('API token')).toHaveCount(0)
    })

    test('a server added by hand waits for its key, then says it is connected', async ({ page }) => {
        await page.goto('/quench/setup?step=2')

        await expect(page.getByTestId('setup-step')).toContainText('Provisioning quench-01')
        await expect(page.getByTestId('setup-step')).toContainText('Waiting for its key')

        await page.goto('/temper/setup?step=2')

        await expect(page.getByTestId('setup-step')).toContainText('Provisioning temper-01')
        await expect(page.getByTestId('setup-step')).toContainText('Connected')
        await expect(page.getByTestId('setup-step')).not.toContainText('Waiting for its key')
    })

    test('connecting a provider from setup checks the token first', async ({ page }) => {
        await page.goto('/foundry/setup?step=1')
        await page.getByLabel('Provider').selectOption('connect:hetzner')
        await page.getByLabel('API token').fill('not-a-real-token')
        await page.getByRole('button', { name: 'Continue' }).click()

        await expect(page.getByTestId('setup-step')).toContainText("Coresmith couldn't reach Hetzner Cloud")
    })

    test('a server being created is followed on the provisioning step', async ({ page }) => {
        await page.goto('/kiln/setup?step=2')

        const step = page.getByTestId('setup-step')

        await expect(step).toContainText('Creating kiln-01 at Hetzner Cloud')
        await expect(step).toContainText('Step 3 of 4')
        await expect(step).toContainText('Provisioning starts by itself once Coresmith can sign in.')
    })

    test('a server being created moves on in setup without reloading the page', async ({ page }) => {
        let moved = false

        await page.clock.install()
        await page.route(
            (url) => url.pathname === '/kiln/setup',
            async (route) => {
                if (!moved || !route.request().headers()['x-inertia']) {
                    return route.continue()
                }

                const response = await route.fetch()
                const visit = await response.json()
                const task = visit.props?.server?.cloud?.task

                if (task) {
                    task.position = 4
                    task.steps = task.steps.map((entry: Record<string, unknown>, index: number) => ({
                        ...entry,
                        state: index < 3 ? 'done' : 'running'
                    }))
                }

                await route.fulfill({ response, json: visit })
            }
        )
        await page.goto('/kiln/setup?step=2')

        const step = page.getByTestId('setup-step')

        await expect(step).toContainText('Step 3 of 4')

        moved = true
        await page.clock.fastForward(3_500)

        await expect(step).toContainText('Step 4 of 4')
        await expect(page).toHaveURL(/\/kiln\/setup\?step=2$/)
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
        await expect(page).toHaveTitle('stub')

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
        await expect(page).toHaveTitle('stub')
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
        await expect(page).toHaveTitle('stub')
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

    const finishedElsewhere = [
        {
            state: 'an App ready to install',
            github: {
                connect_href: 'https://github.com/apps/coresmith/installations/new',
                registration: null,
                elsewhere: null
            },
            action: { role: 'link', name: 'Connect GitHub' }
        },
        {
            state: 'an App made on the panel address',
            github: {
                connect_href: null,
                registration: null,
                elsewhere: 'https://panel.example.com/settings/source-providers'
            },
            action: { role: 'link', name: 'Open panel.example.com' }
        },
        {
            state: 'a member who cannot connect GitHub',
            github: { can_manage: false, connect_href: null, registration: null, elsewhere: null },
            action: null
        }
    ] as const

    for (const { state, github, action } of finishedElsewhere) {
        test(`a team on GitLab alone moves on beside ${state}`, async ({ page }) => {
            await openSetupWith(page, 'tidewater', { connected: false, ...github })

            const step = page.getByTestId('setup-step')

            if (action !== null) {
                await expect(step.getByRole(action.role, { name: action.name })).toBeVisible()
            }

            await step.getByRole('button', { name: 'Continue' }).click()

            await expect(page.getByTestId('setup-step')).toContainText('Add your first server')
        })
    }

    test.describe('when the visit to the first step is slow', () => {
        const member = { connected: false, can_manage: false, connect_href: null, registration: null, elsewhere: null }

        test('continuing moves on from the first step, not from the step that was showing', async ({ page }) => {
            await openSetupWith(page, 'tidewater', member, 1_000)

            const step = page.getByTestId('setup-step')

            await step.getByRole('button', { name: 'Continue' }).click()

            await expect(page).toHaveURL(/[?&]step=1$/)
            await expect(step).toContainText('Add your first server')
        })

        test('the overridden props have rendered by the time the helper returns', async ({ page }) => {
            await openSetupWith(page, 'acme', member, 1_000)

            expect(await page.getByTestId('setup-step').textContent()).toContain('An owner or admin connects GitHub.')
        })
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
