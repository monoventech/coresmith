import { blockingViolations, expect, gotoServer, gotoSettings, asDeveloper as test, useTheme } from './support/fixtures'

test.describe('a developer', () => {
    test('sees the server shared with it and the app it added there, and none of the apps others run on it', async ({
        page
    }) => {
        await page.goto('/dashboard')

        const cards = page.getByTestId('server-card')

        await expect(cards.getByRole('link', { name: 'forge-02', exact: true })).toBeVisible()
        await expect(cards.getByRole('link', { name: 'forge-01', exact: true })).toHaveCount(0)
        await expect(cards.getByRole('link', { name: 'bench-01', exact: true })).toHaveCount(0)

        await gotoServer(page, 'forge-02')

        const apps = page.getByTestId('applications-table')

        await expect(apps.getByRole('link', { name: 'side-notes', exact: true })).toBeVisible()
        await expect(apps.getByRole('link', { name: 'atlas-staging', exact: true })).toHaveCount(0)
        await expect(apps.getByRole('link', { name: 'storefront-staging', exact: true })).toHaveCount(0)
    })

    test('adding a server is offered only as a server of its own over SSH', async ({ page }) => {
        await page.goto('/servers/create')

        await expect(page.getByRole('heading', { level: 1, name: 'Provision a server' })).toBeVisible()
        await expect(page.getByText('Hetzner Cloud', { exact: false })).toHaveCount(0)
        await expect(page.getByLabel('IP address', { exact: true })).toBeVisible()
    })

    test('sees the team settings but cannot change them', async ({ page }) => {
        await gotoSettings(page, 'General')

        await expect(page.getByTestId('panel-card')).toContainText('Coresmith')
        await expect(page.getByTestId('panel-card').getByRole('button', { name: 'Save' })).toHaveCount(0)
        await expect(page.getByTestId('backups-card').getByRole('button', { name: 'Back up now' })).toHaveCount(0)
    })

    test('its fleet passes axe in light and dark', async ({ page }) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await page.goto('/dashboard')
            await expect(page.getByTestId('server-card').first()).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        }
    })
})
