import { expect, settled, test } from './support/fixtures'

test.describe('Settings', () => {
    test('the profile page loads with the current details', async ({ page }) => {
        await page.goto('/settings/profile')

        await expect(page.getByRole('heading', { name: 'Settings' }).first()).toBeVisible()
        await expect(page.getByLabel('Name')).toHaveValue('Ada Owner')
    })

    test('the profile name can be changed', async ({ page }) => {
        await page.goto('/settings/profile')

        await page.getByLabel('Name').fill('Ada Lovelace')
        await page.getByRole('button', { name: /Save/i }).first().click()

        await page.reload()
        await expect(page.getByLabel('Name')).toHaveValue('Ada Lovelace')

        await page.getByLabel('Name').fill('Ada Owner')
        await page.getByRole('button', { name: /Save/i }).first().click()
    })

    test('the settings navigation moves between sections', async ({ page }) => {
        await page.goto('/settings/profile')

        await page.getByRole('link', { name: 'Appearance' }).first().click()
        await expect(page).toHaveURL(/appearance/)

        await page.getByRole('link', { name: 'Profile' }).first().click()
        await expect(page).toHaveURL(/profile/)
    })

    test('the security section asks for the password again before opening', async ({ page }) => {
        await page.goto('/settings/profile')
        await page.getByRole('link', { name: 'Security' }).first().click()

        await expect(page).toHaveURL(/confirm-password|security/)
    })

    test('ssh keys can be added and removed', async ({ page }) => {
        await page.goto('/ssh-keys')

        const material = Buffer.from(`e2e-key-${Date.now()}`).toString('base64')
        await page.getByLabel('Name').fill('E2E laptop')
        await page.getByLabel('Public key').fill(`ssh-ed25519 ${material} me@laptop`)
        await page.getByRole('button', { name: 'Add key' }).click()

        await settled(page, 'E2E laptop')
        await expect(page.getByText('E2E laptop')).toBeVisible()

        await page.getByRole('button', { name: 'Remove' }).first().click()
        await expect(page.getByText('E2E laptop')).toHaveCount(0)
    })

    test('a malformed ssh key is refused', async ({ page }) => {
        await page.goto('/ssh-keys')

        await page.getByLabel('Name').fill('Bad key')
        await page.getByLabel('Public key').fill('this is not a key')
        await page.getByRole('button', { name: 'Add key' }).click()

        await settled(page, 'format is invalid')
    })
})
