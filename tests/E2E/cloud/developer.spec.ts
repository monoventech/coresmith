import { asCloudDeveloper as test, blockingViolations, expect, useTheme } from '../support/fixtures'

test.describe('a developer on a Coresmith Cloud team', () => {
    test('sees the server shared with it, the server it added and its own app, and nothing else in the team', async ({
        page
    }) => {
        await page.goto('/monoven/dashboard')

        const cards = page.getByTestId('server-card')

        await expect(cards.getByRole('link', { name: 'forge-02', exact: true })).toBeVisible()
        await expect(cards.getByRole('link', { name: 'lena-01', exact: true })).toBeVisible()
        await expect(cards.getByRole('link', { name: 'forge-01', exact: true })).toHaveCount(0)
        await expect(cards.getByRole('link', { name: 'anvil-01', exact: true })).toHaveCount(0)

        await cards.getByRole('link', { name: 'forge-02', exact: true }).click()

        const apps = page.getByTestId('applications-table')

        await expect(apps.getByRole('link', { name: /^side-notes / })).toBeVisible()
        await expect(apps.getByRole('link', { name: /^atlas-staging / })).toHaveCount(0)
    })

    test('adds a server on a Pro team with no plan limit in the way', async ({ page }) => {
        await page.goto('/monoven/servers/create')

        await expect(page.getByRole('heading', { level: 1, name: 'Provision a server' })).toBeVisible()
        await expect(page.getByLabel('Provider')).toBeVisible()
        await expect(page.getByTestId('server-limit')).toHaveCount(0)
    })

    test('adds an app on the servers it can see, and only those', async ({ page }) => {
        await page.goto('/monoven/dashboard')
        await page.getByTestId('fleet-actions').getByRole('link', { name: 'Add an app' }).click()

        const servers = page.getByRole('list', { name: 'Servers' })

        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
        await expect(servers).toContainText('forge-02')
        await expect(servers).toContainText('lena-01')
        await expect(servers).not.toContainText('forge-01')
    })

    test('connects a GitHub of its own from Your account, on its own account only', async ({ page }) => {
        await page.goto('/monoven/settings/account/git')

        const card = page.getByTestId('own-github')

        await expect(card.getByRole('heading', { name: 'Your own GitHub in Monoven Ltd' })).toBeVisible()
        await expect(card).toContainText('No one else in the team can list, read or add apps from them.')
        await expect(card.getByRole('button', { name: 'Create on GitHub' })).toBeVisible()
    })

    test('its fleet passes axe in light and dark', async ({ page }) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await page.goto('/monoven/dashboard')
            await expect(page.getByTestId('server-card').first()).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        }
    })
})
