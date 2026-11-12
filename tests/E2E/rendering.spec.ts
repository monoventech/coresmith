import { expect, gotoApplication, gotoServerTab, noSidewaysScroll, test } from './support/fixtures'

const PATHS = [
    '/dashboard',
    '/deployments',
    '/releases',
    '/servers/create',
    '/settings',
    '/settings/team',
    '/settings/git',
    '/settings/profile'
]

test.describe('rendering across browsers', () => {
    for (const path of PATHS) {
        test(`${path} has one page heading and does not scroll sideways`, async ({ page }) => {
            await page.goto(path)

            await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
            await noSidewaysScroll(page)
        })

        test(`${path} can be entered from the keyboard`, async ({ page }, testInfo) => {
            await page.goto(path)
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

            if (testInfo.project.name === 'webkit' || testInfo.project.name === 'mobile-safari') {
                const reachable = await page.evaluate(() => {
                    const first = Array.from(
                        document.querySelectorAll<HTMLElement>(
                            'main a[href], main button, main input:not([type=hidden]), main select, main textarea'
                        )
                    ).find((element) => element.getClientRects().length > 0)

                    first?.focus()

                    return first !== undefined && document.activeElement === first
                })

                expect(reachable).toBe(true)

                return
            }

            await page.keyboard.press('Tab')

            const focused = await page.evaluate(() => document.activeElement?.tagName ?? '')

            expect(['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA']).toContain(focused)
        })
    }

    test('the server hardening page does not scroll sideways', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Hardening')

        await noSidewaysScroll(page)
    })

    test('the application page does not scroll sideways', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await noSidewaysScroll(page)
    })

    test('keyboard focus draws the Monoven focus ring', async ({ page }) => {
        await page.goto('/servers/create')

        const field = page.getByLabel('Server name')

        await field.focus()

        const ring = await field.evaluate((element) => {
            const style = window.getComputedStyle(element)

            return { shadow: style.boxShadow, outline: style.outlineStyle }
        })

        expect(ring.shadow !== 'none' || ring.outline !== 'none').toBe(true)
    })

    test('dark mode keeps text readable against the background', async ({ page }) => {
        await page.goto('/dashboard')

        const colours = await page.evaluate(() => {
            const body = window.getComputedStyle(document.body)

            return { background: body.backgroundColor, foreground: body.color }
        })

        expect(colours.background).not.toBe(colours.foreground)
        expect(colours.background).not.toBe('rgb(255, 255, 255)')
    })

    test('the panel loads no error tracking without a dsn', async ({ page }) => {
        await page.goto('/dashboard')
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

        await expect(page.locator('meta[name="coresmith-sentry"]')).toHaveCount(0)
        expect(await page.evaluate(() => '__SENTRY__' in globalThis)).toBe(false)
    })
})
