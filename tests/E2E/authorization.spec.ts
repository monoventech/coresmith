import { asMember, expect } from './support/fixtures'

asMember.describe('What a member may do', () => {
    asMember('a member can see servers', async ({ page }) => {
        await page.goto('/servers')

        await expect(page.getByRole('link', { name: 'web-1' })).toBeVisible()
    })

    asMember('a member is not offered the add server action', async ({ page }) => {
        await page.goto('/servers')

        await expect(page.getByRole('link', { name: 'Add a server' })).toHaveCount(0)
    })

    asMember('a member reaching the create page directly is refused', async ({ page }) => {
        const response = await page.goto('/servers/create')

        expect(response?.status()).toBe(403)
    })

    asMember('a member is not offered provisioning controls', async ({ page }) => {
        await page.goto('/servers')
        await page.getByRole('main').getByRole('link', { name: 'web-1' }).click()

        await expect(page.getByRole('button', { name: 'Provision' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Test connection' })).toHaveCount(0)
    })

    asMember('a member can still deploy but cannot open the environment', async ({ page }) => {
        await page.goto('/servers')
        await page.getByRole('main').getByRole('link', { name: 'web-1' }).click()
        await page.getByRole('main').getByRole('link', { name: 'Applications' }).click()
        await page.getByRole('link', { name: 'Storefront', exact: true }).click()

        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeVisible()
        await expect(page.getByRole('link', { name: 'Environment' })).toHaveCount(0)
    })

    asMember('a member cannot reach the environment page directly', async ({ page }) => {
        await page.goto('/servers')
        await page.getByRole('main').getByRole('link', { name: 'web-1' }).click()
        await page.getByRole('main').getByRole('link', { name: 'Applications' }).click()
        await page.getByRole('link', { name: 'Storefront', exact: true }).click()
        await page.waitForURL(/\/applications\/[A-Za-z0-9]+$/)

        const response = await page.goto(`${page.url()}/env`)

        expect(response?.status()).toBe(403)
    })
})
