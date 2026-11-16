import type { Page } from '@playwright/test'
import { expect, asPartlyShared as test } from './support/fixtures'

function palette(page: Page) {
    return page.getByRole('dialog', { name: 'Command palette' })
}

test.describe('an admin Storefront is shared with, holding back its production app', () => {
    test('the fleet lists the shared app, and the projects tab shows Storefront with only that app', async ({
        page
    }) => {
        await page.goto('/dashboard')

        await expect(page.getByTestId('server-card')).toHaveCount(0)
        await expect(page.getByTestId('shared-apps')).toContainText('storefront-staging')

        await page.goto('/projects')
        await expect(page.getByTestId('project-card')).toHaveCount(1)
        await expect(page.getByTestId('project-card-app')).toHaveText(['storefront-staging staging'])
        await expect(page.getByTestId('no-project')).toHaveCount(0)
    })

    test('the project page shows only what is shared, offers no rename, and the server behind it is not found', async ({
        page
    }) => {
        await page.goto('/projects')
        await page.getByTestId('project-card').getByRole('link', { name: 'Storefront', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Storefront' })).toBeVisible()
        await expect(page.getByTestId('project-app')).toHaveCount(1)
        await expect(page.getByRole('button', { name: 'Rename', exact: true })).toHaveCount(0)
        await expect(page.getByTestId('project-people')).toHaveCount(0)

        await page.getByTestId('project-app').getByRole('link', { name: 'storefront-staging', exact: true }).click()
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
