import type { Page } from '@playwright/test'
import { asGuest, blockingViolations, expect, test, useTheme } from '../support/fixtures'

test.use({ reducedMotion: 'reduce' })

type Visit = (page: Page) => Promise<void>

const direct =
    (path: string): Visit =>
    async (page) => {
        await page.goto(path)
    }

const PAGES: [string, Visit][] = [
    ['General settings', direct('/monoven/settings')],
    ['Fleet', direct('/monoven/dashboard')]
]

const OTHER_TEAM_PAGES: [string, Visit][] = [
    ['Fleet on a trial', direct('/trial/dashboard')],
    ['Lapsed team', direct('/expired/dashboard')],
    ['Failed payment', direct('/overdue/dashboard')],
    ['Starter team settings', direct('/solo/settings/team')]
]

const GUEST_PAGES: [string, string][] = [
    ['Sign in', '/login'],
    ['Create an account', '/register']
]

for (const theme of ['light', 'dark'] as const) {
    test.describe(`cloud accessibility in ${theme}`, () => {
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

    asGuest.describe(`cloud guest accessibility in ${theme}`, () => {
        for (const [label, path] of GUEST_PAGES) {
            asGuest(`${label} has no serious or critical violations`, async ({ page }) => {
                await useTheme(page, theme)
                await page.goto(path)

                expect(await blockingViolations(page), `${label} in ${theme}`).toEqual([])
            })
        }
    })
}
