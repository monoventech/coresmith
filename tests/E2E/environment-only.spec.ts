import type { Page } from '@playwright/test'
import { expect, asEnvironmentOnly as test } from './support/fixtures'

function palette(page: Page) {
    return page.getByRole('dialog', { name: 'Command palette' })
}

test.describe('an admin who sees only Storefront staging', () => {
    test('the fleet lists the app shared through the environment, and the projects tab only that environment', async ({
        page
    }) => {
        await page.goto('/dashboard')

        await expect(page.getByTestId('server-card')).toHaveCount(0)
        await expect(page.getByTestId('shared-apps')).toContainText('storefront-staging')

        await page.goto('/projects')
        await expect(page.getByTestId('project-card')).toHaveCount(1)
        await expect(page.getByTestId('project-environment')).toHaveText(['Staging 1'])
        await expect(page.getByTestId('no-project')).toHaveCount(0)
    })

    test('the project page shows only staging, and the server behind it is not found', async ({ page }) => {
        await page.goto('/projects')
        await page.getByTestId('project-card').getByRole('link', { name: 'Storefront', exact: true }).click()

        await expect(page.getByTestId('environment')).toHaveCount(1)
        await expect(page.getByRole('heading', { level: 2, name: 'Staging' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Add environment' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Add apps or databases' })).toBeVisible()

        await page.getByTestId('environment').getByRole('link', { name: 'storefront-staging', exact: true }).click()
        await expect(page.getByRole('heading', { level: 1, name: 'storefront-staging' })).toBeVisible()

        const server = /\/servers\/([A-Za-z0-9]+)\/applications\//.exec(page.url())?.[1]

        expect(server).toBeTruthy()
        expect((await page.goto(`/servers/${server}`))?.status()).toBe(404)
    })

    test('search finds the shared project and nothing else', async ({ page }) => {
        await page.goto('/dashboard')
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.keyboard.press('ControlOrMeta+K')
        await expect(palette(page)).toBeVisible()

        await page.keyboard.type('Open')
        await expect(palette(page).getByRole('option', { name: /Open Storefront/ })).toBeVisible()
        await expect(palette(page).getByRole('option', { name: /Open Atlas/ })).toHaveCount(0)
    })
})
