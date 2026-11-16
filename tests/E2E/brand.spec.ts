import { asGuest, expect, isMobile, main, nav, test } from './support/fixtures'

const MARK = '5.5 5.5 53 53'
const LOCKUP = '0 -87.5 624.4 100'

asGuest.describe('the Coresmith logo on the way in', () => {
    asGuest('the sign in page shows the lockup, and every icon and the web manifest load', async ({ page }) => {
        await page.goto('/login')

        await expect(page.getByRole('link', { name: 'Coresmith home' }).locator('svg')).toHaveAttribute(
            'viewBox',
            LOCKUP
        )

        for (const path of [
            '/favicon.ico',
            '/favicon.svg',
            '/apple-touch-icon.png',
            '/site.webmanifest',
            '/og-image.png'
        ]) {
            expect((await page.request.get(path)).status(), path).toBe(200)
        }

        const manifest = await (await page.request.get('/site.webmanifest')).json()

        for (const icon of manifest.icons as { src: string }[]) {
            expect((await page.request.get(icon.src)).status(), icon.src).toBe(200)
        }
    })
})

test.describe('the Coresmith logo inside', () => {
    test('the rail carries the mark, which takes its name from the link', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The rail starts at 640px; phones show the mark in the header')

        await page.goto('/dashboard')

        const home = nav(page).getByRole('link', { name: 'Coresmith', exact: true })

        await expect(home.locator('svg')).toHaveAttribute('viewBox', MARK)
        await expect(home.locator('svg')).toHaveAttribute('aria-hidden', 'true')
    })

    test('a phone shows the mark at the top of the fleet', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'Wider screens carry the mark in the rail')

        await page.goto('/dashboard')

        await expect(main(page).locator(`svg[viewBox="${MARK}"]`)).toBeVisible()
    })
})
