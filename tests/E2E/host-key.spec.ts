import { asMember, dialog, expect, gotoServer, ORIGINAL_HOST_KEY, own, PRESENTED_HOST_KEY, test, toast } from './support/fixtures'

test.describe('a changed host key', () => {
    test('the server page leads with the warning', async ({ page }) => {
        await gotoServer(page, 'rekeyed-server')

        const alert = page.getByTestId('host-key-alert')

        await expect(alert).toBeVisible()
        await expect(alert).toContainText('rekeyed-server presented a different host key.')
        await expect(alert).toContainText('Every operation on this server is paused until you decide.')
    })

    test('both fingerprints are shown so they can be compared', async ({ page }) => {
        await gotoServer(page, 'rekeyed-server')

        await expect(page.getByTestId('host-key-alert')).toContainText(ORIGINAL_HOST_KEY)
        await expect(page.getByTestId('observed-fingerprint')).toHaveText(PRESENTED_HOST_KEY)
    })

    test('a healthy server shows no warning at all', async ({ page }) => {
        await gotoServer(page, 'forge-01')

        await expect(page.getByTestId('host-key-alert')).toHaveCount(0)
    })

    test('trusting asks for confirmation before anything changes', async ({ page }) => {
        await gotoServer(page, 'rekeyed-server')

        await page.getByRole('button', { name: 'Trust the new key' }).click()

        await expect(dialog(page)).toContainText('Trust this new host key?')
        await expect(dialog(page)).toContainText(PRESENTED_HOST_KEY)

        await dialog(page).getByRole('button', { name: 'Keep it blocked' }).click()

        await expect(dialog(page)).toHaveCount(0)
        await expect(page.getByTestId('host-key-alert')).toBeVisible()
    })

    test('confirming trusts the presented key and clears the warning', async ({ page }, testInfo) => {
        const name = own(testInfo, 'rekeyed')

        await gotoServer(page, name)

        await page.getByRole('button', { name: 'Trust the new key' }).click()
        await dialog(page).getByRole('button', { name: 'Trust this key' }).click()

        await expect(toast(page)).toContainText('New host key accepted.')
        await expect(page.getByTestId('host-key-alert')).toHaveCount(0)

        await page.reload()

        await expect(page.getByTestId('host-key-alert')).toHaveCount(0)
    })

    test('dismissing keeps the original key as the only trusted one', async ({ page }, testInfo) => {
        await gotoServer(page, own(testInfo, 'dismiss'))

        await page.getByRole('button', { name: 'Dismiss for now' }).click()

        await expect(toast(page)).toContainText('Dismissed. The original key is still the only one trusted')
        await expect(page.getByTestId('host-key-alert')).toHaveCount(0)
    })
})

asMember.describe('a changed host key as a member', () => {
    asMember('a member is warned but cannot decide', async ({ page }) => {
        await gotoServer(page, 'rekeyed-server')

        await expect(page.getByTestId('host-key-alert')).toContainText('presented a different host key')
        await expect(page.getByRole('button', { name: 'Trust the new key' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Dismiss for now' })).toHaveCount(0)
    })
})
