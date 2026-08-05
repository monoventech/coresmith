import { expect, test } from '@playwright/test'

test.describe('Servers', () => {
    test('the list renders seeded servers', async ({ page }) => {
        await page.goto('/servers')

        await expect(page.getByRole('heading', { name: 'Servers' })).toBeVisible()
        await expect(page.getByRole('link', { name: 'web-1' })).toBeVisible()
        await expect(page.getByText('203.0.113.10')).toBeVisible()
    })

    test('a server detail page shows its public key and details', async ({ page }) => {
        await page.goto('/servers')
        await page.getByRole('link', { name: 'web-1' }).click()

        await expect(page.getByRole('heading', { name: 'web-1' })).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Public key' })).toBeVisible()
        await expect(page.getByText(/^ssh-ed25519 /)).toBeVisible()
        await expect(page.getByText('ubuntu 24.04')).toBeVisible()
    })

    test('the private key is never present in the page', async ({ page }) => {
        await page.goto('/servers')
        await page.getByRole('link', { name: 'web-1' }).click()

        const html = await page.content()

        expect(html).not.toContain('BEGIN OPENSSH PRIVATE KEY')
        expect(html).not.toContain('ssh_private_key')
    })

    test('a new server can be created', async ({ page }) => {
        const name = `e2e-${Date.now()}`

        await page.goto('/servers/create')
        await page.getByLabel('Name').fill(name)
        await page.getByLabel('IP address').fill('198.51.100.7')
        await page.getByRole('button', { name: 'Add server' }).click()

        await expect.poll(async () => (await page.content()).includes(name), { timeout: 15_000 }).toBe(true)

        await expect(page.getByRole('heading', { name })).toBeVisible()
        await expect(page.getByText(/^ssh-ed25519 /)).toBeVisible()
    })

    test('invalid input is reported rather than accepted', async ({ page }) => {
        await page.goto('/servers/create')
        await page.getByLabel('Name').fill('bad-address')
        await page.getByLabel('IP address').fill('not-an-ip')

        await page.getByRole('button', { name: 'Add server' }).click()

        await expect
            .poll(async () => (await page.content()).includes('valid IP address'), { timeout: 15_000 })
            .toBe(true)

        await expect(page.getByText(/valid IP address/i)).toBeVisible()
    })
})
