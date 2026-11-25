import { applicationRow, blockingViolations, expect, gotoServer, isMobile, test, useTheme } from './support/fixtures'

test.describe("a server's processes", () => {
    test("each app's row sums up its processes, naming trouble before counting what runs", async ({
        page
    }, testInfo) => {
        test.skip(isMobile(testInfo), 'The apps table leaves its processes column out below 640px')

        await gotoServer(page, 'busy-01')

        await expect(applicationRow(page, 'busy-shop').getByTestId('app-processes')).toHaveText('1 failed, 1 keeps restarting')
        await expect(applicationRow(page, 'busy-api').getByTestId('app-processes')).toHaveText('2 running')
        await expect(applicationRow(page, 'busy-blog').getByTestId('app-processes')).toHaveText('Sleeping')
    })

    test('the processes live on each app, each with its state, and the server lists none of its own', async ({
        page
    }) => {
        await gotoServer(page, 'busy-01')
        await expect(page.getByRole('heading', { level: 2, name: 'Processes' })).toHaveCount(0)

        await applicationRow(page, 'busy-shop').click()
        await expect(page.getByRole('heading', { level: 1, name: 'busy-shop' })).toBeVisible()

        const card = page.getByTestId('processes-card')

        await expect(card.getByTestId('process-web')).toContainText('Serves requests with PHP 8.5.10')
        await expect(card.getByTestId('process-web')).toContainText('Running')
        await expect(card.getByTestId('toggle-queue_worker')).toContainText('Restarted 14 times today')
        await expect(card.getByTestId('toggle-reverb')).toContainText('Failed')
        await expect(card.getByTestId('toggle-scheduler')).toContainText('Off')
        await expect(page.getByTestId('health-processes')).toContainText('1 failed, 1 keeps restarting')
        await expect(page.getByTestId('app-uptime-empty')).toHaveText(
            'No uptime yet. It shows here once Coresmith has checked the app from outside.'
        )
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the server page and an app's processes are accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoServer(page, 'busy-01')
            await expect(applicationRow(page, 'busy-shop')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])

            await applicationRow(page, 'busy-shop').click()
            await expect(page.getByTestId('processes-card').getByTestId('toggle-reverb')).toContainText('Failed')

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
