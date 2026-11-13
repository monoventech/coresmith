import type { Page } from '@playwright/test'
import { blockingViolations, expect, gotoApplication, own, test, useTheme } from './support/fixtures'

function toggle(page: Page, name: string) {
    return page.getByTestId('smart-toggles').getByRole('switch', { name })
}

test.describe('an app without a domain', () => {
    test('is put on the tailnet from its page, and taken off again', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await expect(page.getByTestId('toggle-tailnet')).toContainText("Serves the app at bench-01's Tailscale address")
        await toggle(page, 'Reachable on your tailnet').click()
        await expect(toggle(page, 'Reachable on your tailnet')).toBeChecked()

        await page.reload()
        await expect(toggle(page, 'Reachable on your tailnet')).toBeChecked()

        await toggle(page, 'Reachable on your tailnet').click()
        await expect(toggle(page, 'Reachable on your tailnet')).not.toBeChecked()
    })

    test('gets a plain HTTP preview that says anyone with the address can open it', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await toggle(page, 'Preview without a domain').click()
        await expect(toggle(page, 'Preview without a domain')).toBeChecked()
        await expect(page.getByTestId('toggle-preview')).toContainText('Plain HTTP at http://198.51.100.40:')
        await expect(page.getByTestId('toggle-preview')).toContainText('Anyone who has the address can open it.')

        await toggle(page, 'Preview without a domain').click()
        await expect(toggle(page, 'Preview without a domain')).not.toBeChecked()
    })

    test('its switches pass axe in light and dark', async ({ page }, testInfo) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))
            await expect(page.getByTestId('toggle-preview')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        }
    })
})
