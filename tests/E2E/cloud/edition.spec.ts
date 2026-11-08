import { asGuest, expect, tabs, test } from '../support/fixtures'

test.describe('coresmith cloud', () => {
    test('the general tab says coresmith cloud looks after updates and backups', async ({ page }) => {
        await page.goto('/monoven/settings')

        await expect(page.getByText('Team and notification preferences.', { exact: true })).toBeVisible()

        await expect(page.getByTestId('panel-updates-card')).toContainText(
            'Coresmith Cloud · updates are managed for you'
        )
        await expect(page.getByTestId('panel-card')).toHaveCount(0)
        await expect(page.getByLabel('Installation name')).toHaveCount(0)
        await expect(page.getByTestId('backups-card')).toContainText('Coresmith Cloud backs itself up every night.')
        await expect(page.getByTestId('backups-card').getByRole('button', { name: 'Back up now' })).toHaveCount(0)
    })

    test('the settings tabs include billing on cloud', async ({ page }) => {
        await page.goto('/monoven/settings')

        await tabs(page, 'Settings').getByRole('link', { name: 'Billing', exact: true }).click()
        await page.waitForURL(/\/monoven\/settings\/billing$/)

        await expect(tabs(page, 'Settings').getByRole('link', { name: 'Billing', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )
    })

    test('cloud has no installer', async ({ page }) => {
        const response = await page.goto('/install?token=anything')

        expect(response?.status()).toBe(404)
    })
})

asGuest.describe('coresmith cloud for a guest', () => {
    asGuest('cloud signs people up', async ({ page }) => {
        await page.goto('/login')

        await expect(page.getByRole('link', { name: 'Create an account' })).toBeVisible()
    })
})
