import type { Page } from '@playwright/test'
import { expect, test } from './support/fixtures'

// On a phone the sidebar is offcanvas, so navigation starts by opening it.
async function openNav(page: Page) {
    const nav = page.getByTestId('main-nav')

    if (!(await nav.isVisible())) {
        await page.getByRole('button', { name: 'Toggle Sidebar' }).first().click()
        await expect(nav).toBeVisible()
    }

    return nav
}

test.describe('the shell', () => {
    test('every section of the product is reachable from the sidebar', async ({ page }) => {
        await page.goto('/dashboard')

        const sidebar = await openNav(page)

        for (const [name, url] of [
            ['Servers', /\/servers$/],
            ['Applications', /\/applications$/],
            ['Databases', /\/databases$/],
            ['Dashboard', /\/dashboard$/]
        ] as const) {
            await openNav(page)
            await sidebar.getByRole('link', { name, exact: true }).click()
            await page.waitForURL(url)
        }
    })

    test('the dashboard reports what exists rather than a placeholder', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page.getByText('No servers yet')).toHaveCount(0)
        await expect(page.getByText('Provisioning arrives with the servers release.')).toHaveCount(0)

        for (const label of ['servers', 'applications', 'databases']) {
            await expect(page.getByTestId(`count-${label}`)).toBeVisible()
        }
    })

    test('the counts on the dashboard match the pages they link to', async ({ page }) => {
        await page.goto('/dashboard')

        const applicationsTile = page.getByTestId('count-applications')
        const count = Number((await applicationsTile.innerText()).replace(/\D/g, ''))

        expect(count).toBeGreaterThan(0)

        await applicationsTile.click()
        await page.waitForURL(/\/applications$/)

        await expect(page.getByRole('row')).toHaveCount(count + 1)
    })

    test('things that need attention are surfaced and lead somewhere', async ({ page }) => {
        await page.goto('/dashboard')

        const attention = page.getByTestId('attention')

        await expect(attention).toBeVisible()
        await expect(attention.getByRole('link').first()).toBeVisible()

        await attention.getByRole('link').first().click()
        await page.waitForURL(/\/servers\/[A-Za-z0-9]+$/)
    })

    test('the applications overview lists every application across servers', async ({ page }) => {
        await page.goto('/applications')

        await expect(page.getByRole('link', { name: 'Storefront', exact: true })).toBeVisible()
        await expect(page.getByRole('link', { name: 'Internal API', exact: true })).toBeVisible()
        await expect(page.getByRole('columnheader', { name: 'Server' })).toBeVisible()
    })

    test('the databases overview lists every database across servers', async ({ page }) => {
        await page.goto('/databases')

        await expect(page.getByRole('link', { name: 'storefront_production', exact: true })).toBeVisible()
        await expect(page.getByRole('columnheader', { name: 'Engine' })).toBeVisible()
    })

    test('an application opened from the overview reaches its own page', async ({ page }) => {
        await page.goto('/applications')
        await page.getByRole('link', { name: 'Storefront', exact: true }).click()
        await page.waitForURL(/\/applications\/[A-Za-z0-9]+$/)

        await expect(page.getByRole('heading', { name: 'Storefront' })).toBeVisible()
    })

    test('the shell carries the instance name, not a hardcoded one', async ({ page }) => {
        await page.goto('/dashboard')
        await openNav(page)

        await expect(page.locator('body')).toContainText('Coresmith')
    })
})
