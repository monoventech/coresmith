import {
    blockingViolations,
    dialog,
    expect,
    gotoServer,
    gotoSettings,
    own,
    asDeveloper as test,
    toast,
    useTheme
} from './support/fixtures'

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

        await expect(apps.getByRole('link', { name: /^side-notes / })).toBeVisible()
        await expect(apps.getByRole('link', { name: /^atlas-staging / })).toHaveCount(0)
        await expect(apps.getByRole('link', { name: /^storefront-staging / })).toHaveCount(0)
    })

    test("adding a server offers the team's cloud accounts and its own VM, but no provider to connect", async ({
        page
    }) => {
        await page.goto('/servers/create')

        await expect(page.getByRole('heading', { level: 1, name: 'Provision a server' })).toBeVisible()
        await expect(page.getByLabel('Provider').locator('option')).toContainText([
            'DigitalOcean',
            'Hetzner Cloud',
            'Linode, token refused',
            'Bring your own VM'
        ])
        await expect(page.getByLabel('Provider').locator('option', { hasText: 'not connected yet' })).toHaveCount(0)
    })

    test('creates a project of its own, renames it and deletes it', async ({ page }, testInfo) => {
        const name = own(testInfo, 'Side', ' ')

        await page.goto('/projects')
        await page.getByRole('button', { name: 'Create a project' }).first().click()
        await dialog(page).getByLabel('Project name').fill(name)
        await dialog(page).getByRole('button', { name: 'Create project' }).click()
        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
        await expect(toast(page)).toContainText(`Created ${name}.`)

        await page.getByRole('button', { name: 'Rename', exact: true }).click()
        await dialog(page).getByLabel('Project name').fill(`${name} notes`)
        await dialog(page).getByRole('button', { name: 'Save' }).click()
        await expect(page.getByRole('heading', { level: 1, name: `${name} notes` })).toBeVisible()

        await page.getByRole('button', { name: 'Delete', exact: true }).click()
        await dialog(page)
            .getByRole('button', { name: `Delete ${name} notes` })
            .click()
        await page.waitForURL(/\/projects$/)
        await expect(toast(page)).toContainText(`Deleted ${name} notes.`)
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
