import type { Page } from '@playwright/test'
import { blockingViolations, expect, nav, test, useTheme } from './support/fixtures'

async function openAnsweredDeploy(page: Page) {
    await page.goto('/dashboard')
    await nav(page).getByRole('link', { name: 'Deploys', exact: true }).click()
    await page.waitForURL(/\/deployments$/)
    await page.getByTestId('deployment-row').filter({ hasText: 'answered-500' }).first().click()
    await page.waitForURL(/\/deployments\/[^/]+/)
}

test.describe('What the app answered once live', () => {
    test('a deploy that went live while the app answered 500 says so, with the error the app logged', async ({
        page
    }) => {
        await openAnsweredDeploy(page)

        const answer = page.getByTestId('deploy-live-answer')

        await expect(answer).toContainText('Live, but the app answered 500.')
        await expect(answer).toContainText('The app logged: No application encryption key has been specified.')
    })

    test('the warning passes axe in light and dark', async ({ page }) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await openAnsweredDeploy(page)
            await expect(page.getByTestId('deploy-live-answer')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        }
    })
})
