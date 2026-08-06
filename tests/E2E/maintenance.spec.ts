import { asMember, expect, gotoApplication, test } from './support/fixtures'

test.describe('stopping and starting an application', () => {
    test('a running application offers Stop and shows no banner', async ({ page }) => {
        await gotoApplication(page, 'Storefront')

        await expect(page.getByRole('button', { name: 'Stop' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Start' })).toHaveCount(0)
        await expect(page.getByTestId('offline-banner')).toHaveCount(0)
    })

    test('a stopped application says so and offers Start instead', async ({ page }) => {
        await gotoApplication(page, 'Retired Blog')

        await expect(page.getByRole('button', { name: 'Start' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Stop' })).toHaveCount(0)
        await expect(page.getByTestId('offline-banner')).toContainText('Visitors see a maintenance page')
    })

    test('the maintenance toggle is disabled while the application is stopped', async ({ page }) => {
        await gotoApplication(page, 'Retired Blog')

        const toggle = page.getByRole('checkbox', { name: 'Show a maintenance page to all visitors' })

        await expect(toggle).toBeDisabled()
        await expect(page.getByText('The application is stopped, so visitors already see one.')).toBeVisible()
    })

    test('maintenance mode can be turned on and off and is remembered', async ({ page }) => {
        await gotoApplication(page, 'Storefront')

        const toggle = page.getByRole('checkbox', { name: 'Show a maintenance page to all visitors' })

        await expect(toggle).not.toBeChecked()

        await toggle.check()
        await expect(page.getByTestId('offline-banner')).toContainText('Maintenance mode is on')

        await page.reload()
        await expect(page.getByRole('checkbox', { name: 'Show a maintenance page to all visitors' })).toBeChecked()

        await page.getByRole('checkbox', { name: 'Show a maintenance page to all visitors' }).uncheck()
        await page.reload()
        await expect(page.getByRole('checkbox', { name: 'Show a maintenance page to all visitors' })).not.toBeChecked()
        await expect(page.getByTestId('offline-banner')).toHaveCount(0)
    })

    test('the maintenance banner explains that processes keep running', async ({ page }) => {
        await gotoApplication(page, 'Storefront')

        await page.getByRole('checkbox', { name: 'Show a maintenance page to all visitors' }).check()
        await expect(page.getByTestId('offline-banner')).toContainText('the processes keep running')

        await page.getByRole('checkbox', { name: 'Show a maintenance page to all visitors' }).uncheck()
        await expect(page.getByTestId('offline-banner')).toHaveCount(0)
    })

    test('stopping an application from the page flips it to Start', async ({ page }) => {
        await gotoApplication(page, 'Storefront')

        await page.getByRole('button', { name: 'Stop' }).click()

        await expect(page.getByRole('button', { name: 'Start' })).toBeVisible()
        await expect(page.getByTestId('offline-banner')).toContainText('Visitors see a maintenance page')

        await page.getByRole('button', { name: 'Start' }).click()

        await expect(page.getByRole('button', { name: 'Stop' })).toBeVisible()
        await expect(page.getByTestId('offline-banner')).toHaveCount(0)
    })
})

asMember.describe('power controls as a member', () => {
    asMember('a member is offered neither the power buttons nor the toggle', async ({ page }) => {
        await gotoApplication(page, 'Retired Blog')

        await expect(page.getByRole('button', { name: 'Start' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Stop' })).toHaveCount(0)
        await expect(page.getByRole('checkbox', { name: 'Show a maintenance page to all visitors' })).toHaveCount(0)
    })

    asMember('a member can still see that the application is stopped', async ({ page }) => {
        await gotoApplication(page, 'Retired Blog')

        await expect(page.getByTestId('offline-banner')).toContainText('This application is stopped')
    })
})
