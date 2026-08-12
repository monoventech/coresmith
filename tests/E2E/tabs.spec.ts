import { expect, gotoApplication, gotoServer, test } from './support/fixtures'

test.describe('tabbed detail pages', () => {
    test('a server carries the sections the spec lays out', async ({ page }) => {
        await gotoServer(page, 'web-1')

        const tabs = page.getByRole('navigation', { name: 'Sections' })

        for (const label of ['Overview', 'Applications', 'Databases', 'Firewall', 'Settings']) {
            await expect(tabs.getByRole('link', { name: label, exact: true })).toBeVisible()
        }

        await expect(tabs.getByRole('link', { name: 'Overview', exact: true })).toHaveAttribute('aria-current', 'page')
    })

    test('each server tab opens and marks itself current', async ({ page }) => {
        await gotoServer(page, 'web-1')

        const tabs = page.getByRole('navigation', { name: 'Sections' })

        for (const [label, url] of [
            ['Applications', /\/applications$/],
            ['Databases', /\/databases$/],
            ['Firewall', /\/firewall$/]
        ] as const) {
            await tabs.getByRole('link', { name: label, exact: true }).click()
            await page.waitForURL(url)
            await expect(
                page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: label, exact: true })
            ).toHaveAttribute('aria-current', 'page')
        }
    })

    test('an application carries its own sections', async ({ page }) => {
        await gotoApplication(page, 'Storefront')

        const tabs = page.getByRole('navigation', { name: 'Sections' })

        for (const label of ['Overview', 'Deployments', 'Features', 'Environment', 'Settings']) {
            await expect(tabs.getByRole('link', { name: label, exact: true })).toBeVisible()
        }
    })

    test('the deployments tab shows the full history, not just the recent few', async ({ page }) => {
        await gotoApplication(page, 'Storefront')
        await page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Deployments' }).click()
        await page.waitForURL(/\/deployments$/)

        await expect(page.getByText('Add the checkout summary')).toBeVisible()
        await expect(page.getByText('Speed up the cart query')).toBeVisible()
    })

    test('the features tab holds what the application runs', async ({ page }) => {
        await gotoApplication(page, 'Storefront')
        await page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Features' }).click()
        await page.waitForURL(/\/features$/)

        await expect(page.getByRole('heading', { name: 'Site settings' })).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Processes' })).toBeVisible()
        await expect(page.getByRole('switch', { name: 'Maintenance page' })).toBeVisible()
    })

    test('the firewall tab is honest about only knowing what Coresmith applied', async ({ page }) => {
        await gotoServer(page, 'web-1')
        await page.getByRole('navigation', { name: 'Sections' }).getByRole('link', { name: 'Firewall' }).click()
        await page.waitForURL(/\/firewall$/)

        await expect(page.getByText(/does not read the live rule table off the server/)).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Changes Coresmith applied' })).toBeVisible()
    })

    test('the overview no longer carries everything at once', async ({ page }) => {
        await gotoApplication(page, 'Storefront')

        await expect(page.getByRole('switch', { name: 'Maintenance page' })).toHaveCount(0)
        await expect(page.getByRole('link', { name: 'See all' })).toBeVisible()
    })
})
