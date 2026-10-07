import type { Page } from '@playwright/test'
import {
    asGuest,
    blockingViolations,
    expect,
    gotoApplication,
    gotoDatabase,
    gotoServer,
    gotoServerTab,
    openSecurity,
    test,
    useTheme
} from './support/fixtures'

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
    ['Application killed for memory', (page) => gotoApplication(page, 'bench-01', 'hungry')],
    ['Application on an end-of-life runtime', (page) => gotoApplication(page, 'bench-01', 'legacy-api')],
    [
        'Add an app',
        async (page) => {
            await gotoServer(page, 'bench-01')
            await page.getByRole('link', { name: 'Add an app' }).click()
            await page.getByRole('radiogroup', { name: 'PHP version' }).waitFor()
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
        'Failed deploy',
        async (page) => {
            await gotoApplication(page, 'bench-01', 'crashy')
            await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()
            await page.getByTestId('failure-banner').waitFor()
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

const OTHER_TEAM_PAGES: [string, string][] = [
    ['Team settings with a domain waiting for DNS', '/acme/settings/team'],
    ['Team settings with a verified domain', '/northwind/settings/team']
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

        for (const [label, path] of OTHER_TEAM_PAGES) {
            test(`${label} has no serious or critical violations`, async ({ page }) => {
                await useTheme(page, theme)
                await page.goto(path)

                const violations = await blockingViolations(page)

                await page.goto('/monoven/dashboard')

                expect(violations, `${label} in ${theme}`).toEqual([])
            })
        }
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
