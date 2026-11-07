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
    ['Fleet', direct('/monoven/dashboard')],
    ['Billing on Pro', direct('/monoven/settings/billing')]
]

const OTHER_TEAM_PAGES: [string, Visit][] = [
    ['Fleet on a trial', direct('/trial/dashboard')],
    ['Lapsed team', direct('/expired/dashboard')],
    ['Failed payment', direct('/overdue/dashboard')],
    ['Starter team settings', direct('/solo/settings/team')],
    ['Billing on a trial', direct('/trial/settings/billing')],
    ['Billing waiting for stripe', direct('/trial/settings/billing?checkout=success')],
    ['Billing on an older price', direct('/old-price/settings/billing')],
    ['Billing with no card on file', direct('/no-card/settings/billing')],
    ['Team settings after the plan ended', direct('/ended-business/settings/team')],
    ['Billing on a lapsed team', direct('/expired/settings/billing')],
    ['General settings on a team its owner can delete', direct('/trial/settings')],
    [
        'Delete team dialog',
        async (page) => {
            await page.goto('/trial/settings')
            await page.getByTestId('danger-zone').getByRole('button', { name: 'Delete this team' }).click()
            await expect(page.getByRole('dialog').getByLabel('Type Forge Trial to confirm')).toBeVisible()
        }
    ],
    [
        'Database backups to S3 dialog on Business',
        async (page) => {
            await page.goto('/atelier/settings/team')
            await page.getByTestId('offsite-backups-card').getByRole('button', { name: 'Change' }).click()
            await expect(page.getByRole('dialog').getByLabel('Bucket')).toBeVisible()
        }
    ],
    ['Team settings sending backups to S3', direct('/atelier/settings/team')],
    [
        'Change plan dialog',
        async (page) => {
            await page.goto('/fleet/settings/billing')
            await page.getByRole('button', { name: 'Change plan' }).click()
            await page.getByRole('dialog').getByRole('radio', { name: 'Starter', exact: true }).click()
            await expect(page.getByRole('dialog').getByTestId('plan-blocker').first()).toBeVisible()
        }
    ]
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
