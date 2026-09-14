import type { Page } from '@playwright/test'
import { dialog, expect, isMobile, nav, test } from './support/fixtures'

function palette(page: Page) {
    return page.getByRole('dialog', { name: 'Command palette' })
}

async function openPalette(page: Page) {
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    await page.keyboard.press('ControlOrMeta+K')
    await expect(palette(page)).toBeVisible()
    await expect(palette(page).getByRole('option').first()).toBeVisible()
}

test.describe('the shell', () => {
    test('every section is reachable from the main navigation', async ({ page }, testInfo) => {
        await page.goto('/dashboard')

        const sections = [
            [isMobile(testInfo) ? 'Deploys' : 'Deployments', /\/deployments$/],
            ['Releases', /\/releases$/],
            ['Settings', /\/settings$/],
            ['Fleet', /\/dashboard$/]
        ] as const

        for (const [name, url] of sections) {
            await nav(page).getByRole('link', { name, exact: true }).click()
            await page.waitForURL(url)
            await expect(nav(page).getByRole('link', { name, exact: true })).toHaveAttribute('aria-current', 'page')
        }
    })

    test('a finished setup takes its item out of the navigation', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(nav(page).getByRole('link', { name: /Finish setup/ })).toHaveCount(0)
    })

    test('the page title names the page and the product', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page).toHaveTitle(/Fleet/)
    })
})

test.describe('the sidebar', () => {
    test.skip(({ isMobile }) => isMobile, 'The sidebar is hidden below 640px; phones navigate with the bottom tabs')

    test('it carries the wordmark, the fleet status and the Monoven mark', async ({ page }) => {
        await page.goto('/dashboard')

        const sidebar = page.getByTestId('sidebar')

        await expect(sidebar.getByText('Coresmith')).toBeVisible()
        await expect(page.getByTestId('fleet-status')).toContainText(/needs? attention|All systems normal/)
        await expect(sidebar.getByText('Made by Monoven')).toBeVisible()
    })

    test('it collapses to a rail and remembers it', async ({ page }) => {
        await page.goto('/dashboard')

        const sidebar = page.getByTestId('sidebar')

        await page.getByRole('button', { name: 'Collapse sidebar' }).click()
        await expect(sidebar).toHaveAttribute('data-collapsed', 'true')
        await expect(sidebar.getByRole('link', { name: 'Deployments' })).toBeVisible()

        await page.reload()
        await expect(page.getByTestId('sidebar')).toHaveAttribute('data-collapsed', 'true')

        await page.getByRole('button', { name: 'Expand sidebar' }).click()
        await expect(page.getByTestId('sidebar')).toHaveAttribute('data-collapsed', 'false')
    })

    test('it starts dark, flips the theme and keeps it across a reload', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page.locator('html')).toHaveClass(/\bdark\b/)

        await page.getByRole('button', { name: 'Light mode' }).click()
        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/)

        await page.reload()
        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/)

        await page.getByRole('button', { name: 'Dark mode' }).click()
        await expect(page.locator('html')).toHaveClass(/\bdark\b/)
    })

    test('the search button opens the command palette', async ({ page }) => {
        await page.goto('/dashboard')

        await page.getByRole('button', { name: 'Search' }).click()

        await expect(palette(page)).toBeVisible()
        await expect(palette(page).getByRole('combobox', { name: 'Search commands' })).toBeFocused()
    })
})

test.describe('the command palette', () => {
    test('it opens from the keyboard and runs a command', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('Deployments')
        await expect(palette(page).getByRole('option')).toHaveCount(1)
        await page.keyboard.press('Enter')

        await page.waitForURL(/\/deployments$/)
        await expect(palette(page)).toHaveCount(0)
    })

    test('it offers the running deploy and every server', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await expect(palette(page).getByRole('option', { name: /Watch the running deploy/ })).toBeVisible()
        await expect(palette(page).getByRole('option', { name: /Open forge-01/ })).toBeVisible()
    })

    test('the arrow keys move the selection', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        const options = palette(page).getByRole('option')

        await expect(options.first()).toHaveAttribute('aria-selected', 'true')
        await page.keyboard.press('ArrowDown')
        await expect(options.nth(1)).toHaveAttribute('aria-selected', 'true')
        await expect(options.first()).toHaveAttribute('aria-selected', 'false')
    })

    test('a search with no match says so', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('zzzz-nothing')

        await expect(palette(page)).toContainText('Nothing matches')
    })

    test('escape closes it', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.press('Escape')

        await expect(palette(page)).toHaveCount(0)
    })

    test('opening a server from it lands on the server', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('Open outpost-01')
        await page.keyboard.press('Enter')

        await expect(page.getByRole('heading', { level: 1, name: 'outpost-01' })).toBeVisible()
    })

    test('switching the theme from it flips the page', async ({ page }) => {
        await page.goto('/dashboard')
        await expect(page.locator('html')).toHaveClass(/\bdark\b/)
        await openPalette(page)

        await page.keyboard.type('Switch theme')
        await page.keyboard.press('Enter')

        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/)
    })
})

test.describe('the account dialog', () => {
    test('it says who is signed in and where they belong', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByRole('button', { name: 'Account' }).click()

        await expect(dialog(page)).toContainText('Signed in as owner@coresmith.test, owner of Monoven Ltd.')
        await expect(dialog(page)).toContainText('Monoven Ltd')
        await expect(dialog(page)).toContainText('current')
    })

    test('it leads to your own account', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByRole('button', { name: 'Account' }).click()
        await dialog(page).getByRole('link', { name: 'Your account' }).click()

        await page.waitForURL(/\/settings\/profile$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Your account' })).toBeVisible()
    })

    test('it leads to the panel settings', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByRole('button', { name: 'Account' }).click()
        await dialog(page).getByRole('link', { name: 'Panel settings' }).click()

        await page.waitForURL(/\/settings$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Settings' })).toBeVisible()
    })
})
