import { expect, test } from '../support/fixtures'

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
