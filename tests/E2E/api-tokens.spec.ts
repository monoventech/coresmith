import type { Page } from '@playwright/test'
import { asMember, expect, test } from './support/fixtures'

async function gotoTokens(page: Page) {
    await page.goto('/settings/profile')
    await page.getByRole('link', { name: 'API tokens' }).click()
    await page.waitForURL(/\/settings\/api-tokens$/)
}

test.describe('api tokens', () => {
    test('the settings nav reaches the page', async ({ page }) => {
        await gotoTokens(page)

        await expect(page.getByRole('heading', { name: 'API tokens' })).toBeVisible()
        await expect(page.getByText(/can never do more than your own role allows/)).toBeVisible()
    })

    test('an owner is offered every ability the role grants', async ({ page }) => {
        await gotoTokens(page)

        await expect(page.getByRole('checkbox', { name: 'server.view' })).toBeEnabled()
        await expect(page.getByRole('checkbox', { name: 'application.deploy' })).toBeEnabled()
        await expect(page.getByRole('checkbox', { name: 'application.update' })).toBeEnabled()
        await expect(page.getByText('Your role does not grant this')).toHaveCount(0)
    })

    test('a token cannot be created without a name', async ({ page }) => {
        await gotoTokens(page)

        await page.getByRole('checkbox', { name: 'server.view' }).check()
        await page.getByRole('button', { name: 'Create token' }).click()

        await expect(page).toHaveURL(/\/settings\/api-tokens$/)
        await expect(page.getByTestId('issued-token')).toHaveCount(0)
    })

    test('a created token is shown exactly once', async ({ page }, testInfo) => {
        // Creating a token is not reversible from the page, so each project uses its own name.
        await gotoTokens(page)

        await page.getByLabel('Name').fill(`CI ${testInfo.project.name}`)
        await page.getByRole('checkbox', { name: 'application.deploy' }).check()
        await page.getByRole('button', { name: 'Create token' }).click()

        const issued = page.getByTestId('issued-token')

        await expect(issued).toBeVisible()
        await expect(issued).toContainText('Copy this now')
        await expect(issued).toContainText('|')

        await page.reload()

        await expect(page.getByTestId('issued-token')).toHaveCount(0)

        const row = page.getByRole('listitem').filter({ hasText: `CI ${testInfo.project.name}` })

        await expect(row).toBeVisible()
        await expect(row).toContainText('application.deploy')
        await expect(row).toContainText('Never used')
    })

    test('a token can be revoked', async ({ page }, testInfo) => {
        await gotoTokens(page)

        const name = `Throwaway ${testInfo.project.name}`

        await page.getByLabel('Name').fill(name)
        await page.getByRole('checkbox', { name: 'server.view' }).check()
        await page.getByRole('button', { name: 'Create token' }).click()

        const row = page.getByRole('listitem').filter({ hasText: name })

        await expect(row).toBeVisible()

        await row.getByRole('button', { name: 'Revoke' }).click()

        await expect(page.getByRole('listitem').filter({ hasText: name })).toHaveCount(0)
    })

    test('the page does not scroll sideways', async ({ page }) => {
        await gotoTokens(page)

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)

        expect(overflow).toBeLessThanOrEqual(1)
    })
})

asMember.describe('api tokens as a member', () => {
    asMember('abilities the member role lacks are offered but disabled, and say why', async ({ page }) => {
        await gotoTokens(page)

        await expect(page.getByRole('checkbox', { name: 'application.deploy' })).toBeEnabled()
        await expect(page.getByRole('checkbox', { name: 'application.update' })).toBeDisabled()
        await expect(page.getByText('Your role does not grant this').first()).toBeVisible()
    })
})
