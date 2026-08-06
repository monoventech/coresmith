import { expect, gotoServer, test } from './support/fixtures'

test.describe('server resources', () => {
    test('the four resource tiles are shown with their current values', async ({ page }) => {
        await gotoServer(page, 'web-1')

        const resources = page.getByRole('heading', { name: 'Resources' }).locator('..').locator('..')

        await expect(resources).toContainText('CPU')
        await expect(resources).toContainText('Memory')
        await expect(resources).toContainText('Disk')
        await expect(resources).toContainText('Load (1m)')
        await expect(resources).toContainText('63.0%')
    })

    test('the sparklines are drawn once there is history', async ({ page }) => {
        await gotoServer(page, 'web-1')

        const sparklines = page.locator('svg polyline')

        await expect(sparklines).toHaveCount(4)
        await expect(page.getByText('Not enough history yet')).toHaveCount(0)
    })

    test('the readings are also available as a table, not colour alone', async ({ page }) => {
        await gotoServer(page, 'web-1')

        await page.getByText('Readings as a table').click()

        const table = page.getByRole('table')

        await expect(table).toBeVisible()
        await expect(table.getByRole('columnheader', { name: 'CPU %' })).toBeVisible()
        await expect(table.getByRole('columnheader', { name: 'Load 1m' })).toBeVisible()
        await expect(table.locator('tbody tr')).toHaveCount(12)
    })

    test('the panel says when the last reading was taken', async ({ page }) => {
        await gotoServer(page, 'web-1')

        const freshness = page.getByTestId('metrics-freshness')

        await expect(freshness).toContainText('Last read')
        await expect(freshness).toContainText('sparklines cover the last hour')
    })

    test('a server with no readings says so instead of showing empty tiles', async ({ page }) => {
        await gotoServer(page, 'empty-server')

        await expect(page.getByTestId('metrics-freshness')).toContainText('No readings yet')
        await expect(page.locator('svg polyline')).toHaveCount(0)
    })

    test('the sparklines are hidden from assistive technology since the table carries the data', async ({ page }) => {
        await gotoServer(page, 'web-1')

        const sparkline = page.locator('svg polyline').first().locator('..')

        await expect(sparkline).toHaveAttribute('aria-hidden', 'true')
    })

    test('the resources panel does not scroll the page sideways', async ({ page }) => {
        await gotoServer(page, 'web-1')

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)

        expect(overflow).toBeLessThanOrEqual(1)
    })
})
