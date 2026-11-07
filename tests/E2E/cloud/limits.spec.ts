import { expect, own, test, toast } from '../support/fixtures'

test.describe('plan limits on coresmith cloud', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('a full starter team is told before it fills the form', async ({ page }) => {
        await page.goto('/solo/servers/create')
        await page.getByLabel('Provider').selectOption('manual')

        const team = page.getByLabel('Team')

        if (await team.count()) {
            await team.selectOption({ label: 'Solo Shop' })
        }

        await expect(page.getByTestId('server-limit')).toContainText('Starter includes 3 servers and this team has 3.')
        await expect(page.getByRole('button', { name: 'Add server' })).toBeDisabled()
    })

    test("a lapsed team's address can add a server to a paying team", async ({ page }, testInfo) => {
        const name = `paid-from-lapsed-${testInfo.retry}`

        await page.goto('/expired/servers/create')
        await page.getByLabel('Provider').selectOption('manual')
        await expect(page.getByTestId('server-limit')).toContainText("This team's trial has ended")

        await page.getByLabel('Team').selectOption({ label: own(testInfo, 'Vault', ' ') })

        await expect(page.getByTestId('server-limit')).toHaveCount(0)

        await page.getByLabel('Server name').fill(name)
        await page.getByLabel('IP address').fill('203.0.113.77')
        await page.getByRole('button', { name: 'Add server' }).click()

        await page.waitForURL(new RegExp(`/${own(testInfo, 'vault')}/servers/[A-Za-z0-9]+$`))
        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
        await expect(toast(page)).toContainText('Server added.')
    })

    test('starter cannot invite', async ({ page }) => {
        await page.goto('/solo/settings/team')

        await expect(page.getByRole('button', { name: 'Invite someone' })).toBeDisabled()

        const note = page.getByTestId('team-card').getByTestId('plan-note')

        await expect(note).toContainText('Starter is for one person.')
        await expect(note.getByRole('link', { name: 'Change plan' })).toBeVisible()
    })

    test('starter has no custom domain', async ({ page }) => {
        await page.goto('/solo/settings/team')

        await expect(
            page.getByTestId('plan-note').filter({ hasText: 'Custom domains are part of Pro and Business.' })
        ).toBeVisible()
        await expect(page.getByRole('button', { name: 'Add a domain' })).toHaveCount(0)
    })

    test('pro has no single sign-on', async ({ page }) => {
        await page.goto('/monoven/settings/team')

        await expect(
            page.getByTestId('plan-note').filter({ hasText: 'Single sign-on is part of Business.' })
        ).toBeVisible()
        await expect(page.getByRole('button', { name: 'Set up single sign-on' })).toHaveCount(0)
    })
})
