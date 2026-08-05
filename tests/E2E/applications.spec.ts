import { expect, test } from '@playwright/test'

test.describe('Applications', () => {
    test('applications are listed for a server', async ({ page }) => {
        await page.goto('/servers')
        await page.getByRole('link', { name: 'web-1' }).click()
        await page.getByRole('link', { name: 'Applications' }).click()

        await expect(page.getByRole('heading', { name: 'Applications' })).toBeVisible()
        await expect(page.getByRole('link', { name: 'Storefront' })).toBeVisible()
    })

    test('an application detail page shows source, runtime and deployments', async ({ page }) => {
        await page.goto('/servers')
        await page.getByRole('link', { name: 'web-1' }).click()
        await page.getByRole('link', { name: 'Applications' }).click()
        await page.getByRole('link', { name: 'Storefront' }).click()

        await expect(page.getByRole('heading', { name: 'Storefront' })).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Source' })).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Runtime' })).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Recent deployments' })).toBeVisible()
        await expect(page.getByText('8000–8009')).toBeVisible()
    })

    test('an empty server offers the empty state rather than a bare table', async ({ page }) => {
        await page.goto('/servers')
        await page.getByRole('link', { name: 'empty-server' }).click()
        await page.getByRole('link', { name: 'Applications' }).click()

        await expect(page.getByText('Nothing deployed here yet')).toBeVisible()
        await expect(page.getByRole('link', { name: 'Create an application' })).toBeVisible()
    })
})
