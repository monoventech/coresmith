import type { Page } from '@playwright/test'
import {
    asGuest,
    backToMonoven,
    blockingViolations,
    expect,
    gotoApplication,
    gotoDatabase,
    gotoServer,
    gotoServerTab,
    openSecurity,
    openSetupWith,
    test,
    useTheme
} from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

type Visit = (page: Page) => Promise<void>

const direct =
    (path: string): Visit =>
    async (page) => {
        await page.goto(path)
    }

const PAGES: [string, Visit][] = [
    ['Fleet', direct('/dashboard')],
    ['Deployments', direct('/deployments')],
    ['Releases', direct('/releases')],
    ['Server apps', (page) => gotoServer(page, 'forge-01')],
    ['Server with a disk warning', (page) => gotoServer(page, 'outpost-01')],
    ['Server with a changed host key', (page) => gotoServer(page, 'rekeyed-server')],
    ['Unreachable server', (page) => gotoServer(page, 'down-01')],
    ['Certificates', (page) => gotoApplication(page, 'bench-01', 'certs-app')],
    ['Realtime toggles', (page) => gotoApplication(page, 'bench-01', 'realtime-chr')],
    ['Server not connected yet', (page) => gotoServer(page, 'empty-server')],
    ['Server checked before provisioning', (page) => gotoServer(page, 'audit-server')],
    ['Server being checked', (page) => gotoServer(page, 'checking-server')],
    ['Server whose provisioning failed', (page) => gotoServer(page, 'halfway-server')],
    ['Server databases', (page) => gotoServerTab(page, 'forge-01', 'Databases')],
    ['Server hardening', (page) => gotoServerTab(page, 'forge-01', 'Hardening')],
    ['Server activity', (page) => gotoServerTab(page, 'forge-01', 'Activity')],
    ['Application', (page) => gotoApplication(page, 'forge-01', 'atlas')],
    ['Stopped application', (page) => gotoApplication(page, 'bench-01', 'retired-blog')],
    ['Failed application', (page) => gotoApplication(page, 'forge-01', 'storefront')],
    ['Application whose deploy crashed after going live', (page) => gotoApplication(page, 'bench-01', 'crashy')],
    ['Application whose new release did not start', (page) => gotoApplication(page, 'bench-01', 'dev-mode')],
    ['Application whose rollback did not start', (page) => gotoApplication(page, 'bench-01', 'failed-rollback')],
    ['Application whose worker did not start', (page) => gotoApplication(page, 'bench-01', 'stuck-worker')],
    ['Application left live after a worker did not start', (page) => gotoApplication(page, 'bench-01', 'no-sidekiq')],
    [
        'Application left live after a rollback stopped a worker',
        (page) => gotoApplication(page, 'bench-01', 'rollback-sidekiq')
    ],
    ['Rails application offered Solid Queue', (page) => gotoApplication(page, 'bench-01', 'solid-jobs')],
    ['Application killed for memory', (page) => gotoApplication(page, 'bench-01', 'hungry')],
    ['Application on an end-of-life runtime', (page) => gotoApplication(page, 'bench-01', 'legacy-api')],
    ['Application linked to GitHub', (page) => gotoApplication(page, 'bench-01', 'linked-app')],
    ['Application whose GitHub connection was removed', (page) => gotoApplication(page, 'bench-01', 'orphaned-app')],
    [
        'Add an app',
        async (page) => {
            await gotoServer(page, 'bench-01')
            await page.getByRole('link', { name: 'Add an app' }).click()
            await page.getByRole('radiogroup', { name: 'PHP version' }).waitFor()
        }
    ],
    [
        'Add an app from GitHub',
        async (page) => {
            await gotoServer(page, 'bench-01')
            await page.getByRole('link', { name: 'Add an app' }).click()
            await page.getByRole('listbox', { name: /repositories/i }).waitFor()
        }
    ],
    [
        'Add an app with a repository picked',
        async (page) => {
            await gotoServer(page, 'bench-01')
            await page.getByRole('link', { name: 'Add an app' }).click()
            await page.getByRole('option', { name: /monoventech\/relay/ }).click()
            await page.getByRole('option', { name: /monoventech\/relay/, selected: true }).waitFor()
        }
    ],
    [
        'Add an app whose repository list did not load',
        async (page) => {
            await page.route('**/settings/source-providers/*/repositories', (route) =>
                route.fulfill({
                    status: 502,
                    json: { message: 'GitHub did not send the repository list. GitHub did not answer.' }
                })
            )
            await gotoServer(page, 'bench-01')
            await page.getByRole('link', { name: 'Add an app' }).click()
            await page.getByRole('button', { name: 'Try again' }).waitFor()
        }
    ],
    [
        'Add an app on an installation with no repositories',
        async (page) => {
            await page.route('**/settings/source-providers/*/repositories', (route) =>
                route.fulfill({ status: 200, json: { repositories: [], total: 0, truncated: false } })
            )
            await gotoServer(page, 'bench-01')
            await page.getByRole('link', { name: 'Add an app' }).click()
            await page.getByRole('link', { name: 'Manage repositories' }).waitFor()
        }
    ],
    [
        'Running deploy',
        async (page) => {
            await page.goto('/dashboard')
            await page.getByTestId('live-deploy').click()
            await page.getByTestId('phase-rail').waitFor()
        }
    ],
    [
        'Cancelled deploy',
        async (page) => {
            await page.goto('/deployments')
            await page.getByTestId('deployment-row').filter({ hasText: 'cancelled-app' }).first().click()
            await page.getByTestId('phase-rail').waitFor()
        }
    ],
    [
        'Failed deploy',
        async (page) => {
            await gotoApplication(page, 'bench-01', 'crashy')
            await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()
            await page.getByTestId('failure-banner').waitFor()
        }
    ],
    [
        'Deploy whose processes did not start',
        async (page) => {
            await gotoApplication(page, 'bench-01', 'dev-mode')
            await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()
            await page.getByTestId('failure-banner').waitFor()
        }
    ],
    [
        'Deploy whose worker did not start',
        async (page) => {
            await gotoApplication(page, 'bench-01', 'no-sidekiq')
            await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()
            await page.getByTestId('failure-banner').waitFor()
        }
    ],
    [
        'Releases with a live release whose deploy failed',
        async (page) => {
            await page.goto('/releases')
            await page
                .getByRole('navigation', { name: 'Applications' })
                .getByRole('link', { name: 'dev-mode', exact: true })
                .click()
            await page.getByRole('heading', { level: 1, name: 'Releases · dev-mode' }).waitFor()
        }
    ],
    ['Database', (page) => gotoDatabase(page, 'forge-01', 'atlas_production')],
    ['Database on an existing server', (page) => gotoDatabase(page, 'forge-01', 'legacy_reports')],
    ['Provision a server', direct('/servers/create')],
    ['Setup', direct('/setup')],
    ['General settings', direct('/settings')],
    ['Team settings', direct('/settings/team')],
    ['Git settings', direct('/settings/git')],
    ['Notification settings', direct('/settings/notifications')],
    ['API tokens', direct('/settings/api-tokens')],
    ['Billing', direct('/settings/billing')],
    ['Profile', direct('/settings/profile')],
    ['Security', openSecurity],
    [
        'Account dialog',
        async (page) => {
            await page.goto('/dashboard')
            await page.getByRole('button', { name: 'Account' }).click()
            await page.getByTestId('team-switcher').waitFor()
        }
    ]
]

const OTHER_TEAM_PAGES: [string, Visit][] = [
    ['Team settings with a domain waiting for DNS', direct('/acme/settings/team')],
    ['Team settings with a verified domain', direct('/northwind/settings/team')],
    ['Git settings before GitHub is connected', direct('/acme/settings/git')],
    ['Git settings with a suspended GitHub installation', direct('/paused/settings/git')],
    ['Setup before GitHub is connected', direct('/acme/setup')],
    [
        'Setup for a member before GitHub is connected',
        async (page) => {
            await openSetupWith(page, 'acme', {
                can_manage: false,
                connect_href: null,
                registration: null,
                elsewhere: null
            })
            await page.getByTestId('setup-step').getByText('An owner or admin connects GitHub.').waitFor()
        }
    ]
]

const GUEST_PAGES: [string, string][] = [
    ['Landing', '/'],
    ['Sign in', '/login'],
    ['Sign in to a team with single sign-on', '/northwind/login'],
    ['Single sign-on', '/sso'],
    ['Forgot password', '/forgot-password']
]

for (const theme of ['light', 'dark'] as const) {
    test.describe(`accessibility in ${theme}`, () => {
        for (const [label, visit] of PAGES) {
            test(`${label} has no serious or critical violations`, async ({ page }) => {
                await useTheme(page, theme)
                await visit(page)

                expect(await blockingViolations(page), `${label} in ${theme}`).toEqual([])
            })
        }

        for (const [label, visit] of OTHER_TEAM_PAGES) {
            test(`${label} has no serious or critical violations`, async ({ page }) => {
                await useTheme(page, theme)
                await visit(page)

                const violations = await blockingViolations(page)

                await page.goto('/monoven/dashboard')

                expect(violations, `${label} in ${theme}`).toEqual([])
            })
        }
    })

    test.describe(`accessibility on a team's own domain in ${theme}`, () => {
        const origin = process.env.APP_URL ?? `http://127.0.0.1:${process.env.E2E_PORT ?? 8123}`

        test.use({ proxy: { server: origin } })

        test.afterEach(async ({ page }) => {
            await backToMonoven(page)
        })

        test("Setup on a team's own domain before GitHub is connected has no serious or critical violations", async ({
            page,
            context
        }) => {
            await useTheme(page, theme)

            const cookies = await context.cookies(origin)

            await context.addCookies(
                cookies.map(({ name, value }) => ({ name, value, domain: 'panel.northwind.test', path: '/' }))
            )
            await page.goto('http://panel.northwind.test/setup')
            await page.getByTestId('setup-step').getByText('GitHub sends you back there.').waitFor()

            expect(await blockingViolations(page), `Setup on a team's own domain in ${theme}`).toEqual([])
        })
    })

    asGuest.describe(`guest accessibility in ${theme}`, () => {
        for (const [label, path] of GUEST_PAGES) {
            asGuest(`${label} has no serious or critical violations`, async ({ page }) => {
                await useTheme(page, theme)
                await page.goto(path)

                expect(await blockingViolations(page), `${label} in ${theme}`).toEqual([])
            })
        }
    })
}
