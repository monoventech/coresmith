import type { Page } from '@playwright/test'
import { asMember, expect, gotoApplication, test } from './support/fixtures'

// The maintenance toggle lives on the Features tab per CORESMITH_SPEC section 18.
async function gotoFeatures(page: Page, application: string) {
    await gotoApplication(page, application)
    await page.getByRole('link', { name: 'Features' }).click()
    await page.waitForURL(/\/features$/)
}

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
        await gotoFeatures(page, 'Retired Blog')

        const toggle = page.getByRole('switch', { name: 'Maintenance page' })

        await expect(toggle).toBeDisabled()
        await expect(page.getByText('The application is stopped, so visitors already see one.')).toBeVisible()
    })

    test('maintenance mode can be turned on and off and is remembered', async ({ page }) => {
        await gotoFeatures(page, 'Storefront')

        const toggle = page.getByRole('switch', { name: 'Maintenance page' })

        await expect(toggle).not.toBeChecked()

        await toggle.check()
        await expect(page.getByTestId('offline-banner')).toContainText('Maintenance mode is on')

        await page.reload()
        await expect(page.getByRole('switch', { name: 'Maintenance page' })).toBeChecked()

        await page.getByRole('switch', { name: 'Maintenance page' }).uncheck()
        await page.reload()
        await expect(page.getByRole('switch', { name: 'Maintenance page' })).not.toBeChecked()
        await expect(page.getByTestId('offline-banner')).toHaveCount(0)
    })

    test('the maintenance banner explains that processes keep running', async ({ page }) => {
        await gotoFeatures(page, 'Storefront')

        await page.getByRole('switch', { name: 'Maintenance page' }).check()
        await expect(page.getByTestId('offline-banner')).toContainText('the processes keep running')

        await page.getByRole('switch', { name: 'Maintenance page' }).uncheck()
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
        await expect(page.getByRole('switch', { name: 'Maintenance page' })).toHaveCount(0)
    })

    asMember('a member can still see that the application is stopped', async ({ page }) => {
        await gotoApplication(page, 'Retired Blog')

        await expect(page.getByTestId('offline-banner')).toContainText('This application is stopped')
    })
})
