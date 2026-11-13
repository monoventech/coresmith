import type { Page } from '@playwright/test'
import { asMember, dialog, expect, gotoSettings, noSidewaysScroll, own, test, toast } from './support/fixtures'

async function create(page: Page, name: string, ability: string) {
    await page.getByRole('main').getByRole('button', { name: 'Create a token' }).first().click()
    await dialog(page).getByLabel('Name').fill(name)
    await dialog(page).getByRole('checkbox', { name: ability }).check()
    await dialog(page).getByRole('button', { name: 'Create a token' }).click()
}

test.describe('api tokens', () => {
    test('with no tokens the page explains what they are for', async ({ page }) => {
        await gotoSettings(page, 'API tokens')

        await expect(page.getByRole('main')).toContainText(
            /No API tokens yet\.|Each token is shown once, when it's created/
        )
    })

    test('an owner is offered every ability the role grants', async ({ page }) => {
        await gotoSettings(page, 'API tokens')

        await page.getByRole('main').getByRole('button', { name: 'Create a token' }).first().click()

        await expect(dialog(page)).toContainText('can never do more than your own role allows')
        await expect(dialog(page).getByRole('checkbox', { name: 'server.view' })).toBeEnabled()
        await expect(dialog(page).getByRole('checkbox', { name: 'application.deploy' })).toBeEnabled()
        await expect(dialog(page).getByRole('checkbox', { name: 'application.update' })).toBeEnabled()
        await expect(dialog(page)).not.toContainText("your role doesn't grant this")
    })

    test('a token without a name is refused', async ({ page }) => {
        await gotoSettings(page, 'API tokens')

        await page.getByRole('main').getByRole('button', { name: 'Create a token' }).first().click()
        await dialog(page).getByRole('checkbox', { name: 'server.view' }).check()
        await dialog(page).getByRole('button', { name: 'Create a token' }).click()

        await expect(page.getByTestId('issued-token')).toHaveCount(0)
    })

    test('a created token is shown exactly once', async ({ page }, testInfo) => {
        const name = own(testInfo, 'ci')

        await gotoSettings(page, 'API tokens')
        await create(page, name, 'application.deploy')

        const issued = page.getByTestId('issued-token')

        await expect(issued).toContainText('Copy this token now.')
        await expect(issued.locator('code')).toContainText('|')

        const row = page.getByTestId('token-row').filter({ hasText: name })

        await expect(row).toContainText('deploy')
        await expect(row).toContainText('last used never')

        await page.reload()

        await expect(page.getByTestId('issued-token')).toHaveCount(0)
        await expect(page.getByTestId('token-row').filter({ hasText: name })).toBeVisible()
    })

    test('a token is revoked after confirming', async ({ page }, testInfo) => {
        const name = own(testInfo, 'throwaway')

        await gotoSettings(page, 'API tokens')
        await create(page, name, 'server.view')

        const row = page.getByTestId('token-row').filter({ hasText: name })

        await row.getByRole('button', { name: 'Revoke' }).click()
        await expect(dialog(page)).toContainText(`Revoke ${name}?`)
        await dialog(page).getByRole('button', { name: 'Revoke it' }).click()

        await expect(toast(page)).toContainText('Token revoked.')
        await expect(page.getByTestId('token-row').filter({ hasText: name })).toHaveCount(0)
    })

    test('the page does not scroll sideways', async ({ page }) => {
        await gotoSettings(page, 'API tokens')

        await noSidewaysScroll(page)
    })
})

asMember.describe('api tokens as a member', () => {
    asMember('abilities the member role lacks are shown but disabled, and say why', async ({ page }) => {
        await gotoSettings(page, 'API tokens')

        await page.getByRole('main').getByRole('button', { name: 'Create a token' }).first().click()

        await expect(dialog(page).getByRole('checkbox', { name: 'application.deploy' })).toBeEnabled()
        await expect(dialog(page).getByRole('checkbox', { name: 'application.update' })).toBeDisabled()
        await expect(dialog(page)).toContainText("your role doesn't grant this")
    })
})
