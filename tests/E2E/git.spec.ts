import { asMember, dialog, expect, gotoSettings, test } from './support/fixtures'

test.describe('git settings', () => {
    test('the connected GitHub App shows where it is installed and when its token rotates', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const provider = page.getByTestId('provider-card')

        await expect(provider).toContainText('Installed')
        await expect(provider).toContainText('Installed on monoventech')
        await expect(provider).toContainText(/Token expires in \d+ (minutes|hours), rotated automatically\./)
        await expect(page.getByRole('main')).toContainText('GitHub App with fine-grained, per-repository permissions, not OAuth.')
    })

    test('no token material reaches the page', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const html = await page.content()

        expect(html).not.toContain('ghs_demo_never_rendered')
        expect(html).not.toContain('access_token')
        expect(html).not.toContain('refresh_token')
        expect(html).not.toContain('app_private_key')
        expect(html).not.toContain('BEGIN OPENSSH PRIVATE KEY')
    })

    test('every app with a deploy key is listed and opens the app', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const keys = page.getByTestId('deploy-keys-card')

        await expect(keys).toContainText('One Ed25519 key per app, read-only.')
        await keys.getByRole('link').filter({ has: page.getByText('atlas', { exact: true }) }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'atlas', exact: true })).toBeVisible()
    })

    test('webhooks say how deliveries are verified', async ({ page }) => {
        await gotoSettings(page, 'Git')

        await expect(page.getByTestId('webhooks-card')).toContainText('HMAC-SHA256 verified · replay protection via delivery ID')
    })

    test('disconnecting asks first and can be called off', async ({ page }) => {
        await gotoSettings(page, 'Git')

        await page.getByTestId('provider-card').getByRole('button', { name: 'Disconnect' }).click()

        await expect(dialog(page)).toContainText('Deploy keys stay on each repository, so existing apps keep cloning.')
        await dialog(page).getByRole('button', { name: 'Keep it' }).click()

        await expect(dialog(page)).toHaveCount(0)
        await expect(page.getByTestId('provider-card')).toBeVisible()
    })
})

asMember.describe('git settings as a member', () => {
    asMember('a member sees the connection but cannot change it', async ({ page }) => {
        await gotoSettings(page, 'Git')

        await expect(page.getByTestId('provider-card')).toContainText('Installed on monoventech')
        await expect(page.getByRole('button', { name: 'Disconnect' })).toHaveCount(0)
    })
})
