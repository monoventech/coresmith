import { blockingViolations, expect, gotoServer, test, useTheme } from './support/fixtures'

test.describe("a server's processes", () => {
    test('list trouble first, a failed one then one that keeps restarting, and each opens its app', async ({
        page
    }) => {
        await gotoServer(page, 'busy-01')

        const card = page.getByTestId('processes')
        const rows = card.getByTestId('process-row')

        await expect(card.getByRole('heading', { level: 2, name: 'Processes' })).toBeVisible()
        await expect(rows).toHaveCount(4)
        await expect(rows.nth(0)).toContainText('scheduler')
        await expect(rows.nth(0)).toContainText('Failed')
        await expect(rows.nth(1)).toContainText('queue-worker')
        await expect(rows.nth(1)).toContainText('Restarted 14 times today')
        await expect(rows.nth(2)).toContainText('Asleep')
        await expect(rows.nth(3)).toContainText('Running')

        await rows.nth(1).click()
        await expect(page.getByRole('heading', { level: 1, name: 'busy-shop' })).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the server page with its processes is accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoServer(page, 'busy-01')
            await expect(page.getByTestId('process-row')).toHaveCount(4)

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
