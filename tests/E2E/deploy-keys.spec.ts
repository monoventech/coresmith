import { asMember, expect, gotoApplication, test } from './support/fixtures'

test.describe('deploy keys', () => {
    test('a private repository shows a public key the user can register', async ({ page }) => {
        await gotoApplication(page, 'Internal API')

        const panel = page.getByRole('heading', { name: 'Deploy key' }).locator('..')

        await expect(panel).toBeVisible()
        await expect(panel).toContainText('ssh-ed25519 ')
        await expect(panel).toContainText('read-only deploy key')
    })

    test('the private half of the key is never in the page', async ({ page }) => {
        await gotoApplication(page, 'Internal API')

        const html = await page.content()

        expect(html).not.toContain('BEGIN OPENSSH PRIVATE KEY')
        expect(html).not.toContain('deploy_key_private')
    })

    test('the key is stable across reloads rather than regenerated', async ({ page }) => {
        await gotoApplication(page, 'Internal API')

        const first = await page.getByRole('heading', { name: 'Deploy key' }).locator('..').locator('pre').innerText()

        await page.reload()

        const second = await page.getByRole('heading', { name: 'Deploy key' }).locator('..').locator('pre').innerText()

        expect(first.trim()).toBe(second.trim())
        expect(first.trim()).toMatch(/^ssh-ed25519 [A-Za-z0-9+/=]+/)
    })

    test('a public repository shows no deploy key panel', async ({ page }) => {
        await gotoApplication(page, 'Storefront')

        await expect(page.getByRole('heading', { name: 'Deploy key' })).toHaveCount(0)
    })

    test('the key panel is long and still does not scroll the page sideways', async ({ page }) => {
        await gotoApplication(page, 'Internal API')

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        )

        expect(overflow).toBeLessThanOrEqual(1)
    })
})

asMember.describe('deploy keys as a member', () => {
    asMember('a member can read the deploy key to register it on the repository', async ({ page }) => {
        await gotoApplication(page, 'Internal API')

        await expect(page.getByRole('heading', { name: 'Deploy key' })).toBeVisible()
        expect(await page.content()).not.toContain('BEGIN OPENSSH PRIVATE KEY')
    })
})
