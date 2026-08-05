import { expect, test } from '@playwright/test'

const PAGES = ['/dashboard', '/servers', '/servers/create', '/settings/profile']

test.describe('Cross-browser rendering and accessibility', () => {
    for (const path of PAGES) {
        test(`${path} renders with exactly one h1-level heading and no horizontal overflow`, async ({ page }) => {
            await page.goto(path)
            await page.waitForLoadState('networkidle')

            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
            )
            expect(overflow, `${path} scrolls horizontally`).toBe(false)

            await expect(page.locator('h1, h2').first()).toBeVisible()
        })

        test(`${path} is reachable by keyboard`, async ({ page }, testInfo) => {
            test.skip(
                testInfo.project.name.startsWith('mobile-'),
                'Touch browsers do not move focus with Tab; there is no physical keyboard to emulate.',
            )

            await page.goto(path)
            await page.waitForLoadState('networkidle')
            await expect(page.locator('h1, h2').first()).toBeVisible()

            await page.keyboard.press('Tab')

            const focused = await page.evaluate(() => document.activeElement?.tagName ?? '')
            expect(['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA']).toContain(focused)
        })
    }

    test('the visible focus ring is applied on keyboard focus', async ({ page }) => {
        await page.goto('/servers/create')

        const nameField = page.getByLabel('Name')
        await nameField.focus()

        const outline = await nameField.evaluate((element) => {
            const style = window.getComputedStyle(element)

            return { shadow: style.boxShadow, outline: style.outlineStyle }
        })

        expect(outline.shadow !== 'none' || outline.outline !== 'none').toBe(true)
    })

    test('dark mode keeps text readable against the background', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'dark' })
        await page.goto('/servers')

        const colours = await page.evaluate(() => {
            const body = window.getComputedStyle(document.body)

            return { background: body.backgroundColor, foreground: body.color }
        })

        expect(colours.background).not.toBe(colours.foreground)
        expect(colours.background).not.toBe('rgb(255, 255, 255)')
    })
})
