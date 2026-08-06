import type { Page } from '@playwright/test'
import { asMember, expect, gotoApplication, test } from './support/fixtures'

async function gotoDeployment(page: Page, message: string) {
    await gotoApplication(page, 'Storefront')
    await page.getByRole('link', { name: new RegExp(message) }).click()
    await page.waitForURL(/\/deployments\/[A-Za-z0-9]+$/)
}

test.describe('deployment output', () => {
    test('a deployment on the application page opens its own log', async ({ page }) => {
        await gotoDeployment(page, 'Add the checkout summary')

        await expect(page.getByRole('heading', { name: 'Deployment' })).toBeVisible()
        await expect(page.getByLabel('Deployment output')).toContainText('composer install --no-dev')
        await expect(page.getByLabel('Deployment output')).toContainText('Deployment finished')
    })

    test('a finished deployment says so rather than claiming to be streaming', async ({ page }) => {
        await gotoDeployment(page, 'Add the checkout summary')

        await expect(page.getByTestId('log-transport')).toContainText('Finished')
        await expect(page.getByTestId('log-transport')).toContainText('4 lines')
    })

    test('a running deployment reports that it is refreshing when no socket is configured', async ({ page }) => {
        await gotoDeployment(page, 'Speed up the cart query')

        await expect(page.getByTestId('log-transport')).toContainText('Refreshing every 3s')
        await expect(page.getByTestId('log-transport')).toContainText('2 lines')
    })

    test('the commit and status are shown alongside the output', async ({ page }) => {
        await gotoDeployment(page, 'Add the checkout summary')

        await expect(page.getByText('a1b2c3d4', { exact: true })).toBeVisible()
        await expect(page.getByText('Add the checkout summary')).toBeVisible()
        await expect(page.getByText('Succeeded', { exact: true })).toBeVisible()
    })

    test('the output region is reachable by keyboard so it can be scrolled', async ({ page }) => {
        await gotoDeployment(page, 'Add the checkout summary')

        const log = page.getByLabel('Deployment output')

        await log.focus()
        await expect(log).toBeFocused()
    })

    test('following output can be turned off', async ({ page }) => {
        await gotoDeployment(page, 'Speed up the cart query')

        const follow = page.getByRole('checkbox', { name: 'Follow output' })

        await expect(follow).toBeChecked()
        await follow.uncheck()
        await expect(follow).not.toBeChecked()
    })

    test('the page returns to the application', async ({ page }) => {
        await gotoDeployment(page, 'Add the checkout summary')

        await page.getByRole('link', { name: 'Back to application' }).click()
        await page.waitForURL(/\/applications\/[A-Za-z0-9]+$/)

        await expect(page.getByRole('heading', { name: 'Storefront' })).toBeVisible()
    })

    test('a long log does not scroll the page sideways', async ({ page }) => {
        await gotoDeployment(page, 'Add the checkout summary')

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)

        expect(overflow).toBeLessThanOrEqual(1)
    })
})

asMember.describe('deployment output as a member', () => {
    asMember('a member can read the deployment log', async ({ page }) => {
        await gotoDeployment(page, 'Add the checkout summary')

        await expect(page.getByLabel('Deployment output')).toContainText('Deployment finished')
    })
})
