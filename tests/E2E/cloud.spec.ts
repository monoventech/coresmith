import { asMember, backToMonoven, dialog, expect, own, test, toast } from './support/fixtures'

test.describe('provider accounts', () => {
    test.afterEach(async ({ page }) => {
        await backToMonoven(page)
    })

    test('the team lists its provider accounts and the state of each', async ({ page }) => {
        await page.goto('/settings/team')

        const card = page.getByTestId('cloud-providers-card')

        await expect(card).toContainText('3 accounts')
        await expect(card.getByTestId('cloud-account-row').filter({ hasText: 'DigitalOcean' })).toContainText('1 server')
        await expect(card.getByTestId('cloud-account-row').filter({ hasText: 'Linode' })).toContainText('Token refused')
        await expect(card).not.toContainText('never-sent')
    })

    test('a provider is only added once its token is checked', async ({ page }) => {
        await page.goto('/settings/team')
        await page.getByTestId('cloud-providers-card').getByRole('button', { name: 'Add a provider' }).click()
        await dialog(page).getByLabel('Provider').selectOption('vultr')
        await dialog(page).getByLabel('API token').fill('   ')
        await dialog(page).getByRole('button', { name: 'Connect Vultr' }).click()

        await expect(dialog(page)).toContainText('Paste the API token from Vultr.')

        await dialog(page).getByLabel('API token').fill('not-a-real-key')
        await dialog(page).getByRole('button', { name: 'Connect Vultr' }).click()

        await expect(dialog(page)).toContainText("Coresmith couldn't reach Vultr")
    })

    test('an account a server still uses cannot be removed', async ({ page }) => {
        await page.goto('/settings/team')
        await page.getByTestId('cloud-providers-card').getByRole('button', { name: 'Remove DigitalOcean' }).click()
        await dialog(page).getByRole('button', { name: 'Remove account' }).click()

        await expect(toast(page)).toContainText('outpost-01 was created with this account.')
    })

    test('an unused account is removed after asking', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'lab')}/settings/team`)

        const card = page.getByTestId('cloud-providers-card')

        await card.getByRole('button', { name: 'Remove Spare' }).click()
        await expect(dialog(page)).toContainText('It stays valid at Vultr until you revoke it there.')
        await dialog(page).getByRole('button', { name: 'Remove account' }).click()

        await expect(toast(page)).toContainText('Removed.')
        await expect(card).toContainText('No provider connected yet.')
    })

    test('a token is replaced only once the new one is checked', async ({ page }) => {
        await page.goto('/settings/team')
        await page
            .getByTestId('cloud-account-row')
            .filter({ hasText: 'Linode' })
            .getByRole('button', { name: 'Replace token' })
            .click()
        await dialog(page).getByLabel('API token').fill('not-a-real-token')
        await dialog(page).getByRole('button', { name: 'Replace token' }).click()

        await expect(dialog(page)).toContainText("Coresmith couldn't reach Linode")
    })
})

asMember('a deployer sees the accounts but cannot change them', async ({ page }) => {
    await page.goto('/settings/team')

    const card = page.getByTestId('cloud-providers-card')

    await expect(card).toContainText('Hetzner Cloud')
    await expect(card.getByRole('button', { name: 'Add a provider' })).toHaveCount(0)
    await expect(card.getByRole('button', { name: 'Replace token' })).toHaveCount(0)
    await expect(card.getByRole('button', { name: /^Remove / })).toHaveCount(0)
})
