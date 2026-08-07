import { asMember, expect, gotoServer, test } from './support/fixtures'

const ORIGINAL = 'SHA256:AAAAoriginalkeyfingerprintAAAAAAAAAAAAAAAAAA'
const PRESENTED = 'SHA256:BBBBimpostorkeyfingerprintBBBBBBBBBBBBBBBBBB'

test.describe('a changed host key', () => {
    test('the server page leads with the warning, not with the details', async ({ page }) => {
        await gotoServer(page, 'rekeyed-server')

        const alert = page.getByTestId('host-key-alert')

        await expect(alert).toBeVisible()
        await expect(alert).toContainText('Server host key has changed')
        await expect(alert).toContainText('man-in-the-middle attack or a server reinstall')
        await expect(alert).toContainText('Every operation on this server is blocked')
    })

    test('both fingerprints are shown so they can be compared', async ({ page }) => {
        await gotoServer(page, 'rekeyed-server')

        const alert = page.getByTestId('host-key-alert')

        await expect(alert).toContainText(ORIGINAL)
        await expect(page.getByTestId('observed-fingerprint')).toContainText(PRESENTED)
    })

    test('a healthy server shows no warning at all', async ({ page }) => {
        await gotoServer(page, 'web-1')

        await expect(page.getByTestId('host-key-alert')).toHaveCount(0)
    })

    test('accepting asks for confirmation before it trusts anything', async ({ page }) => {
        await gotoServer(page, 'rekeyed-server')

        await page.getByRole('button', { name: 'Accept new key' }).click()

        const dialog = page.getByRole('alertdialog')

        await expect(dialog).toBeVisible()
        await expect(dialog).toContainText('Trust this new host key?')
        await expect(dialog).toContainText(PRESENTED)

        await dialog.getByRole('button', { name: 'Cancel' }).click()

        await expect(dialog).toHaveCount(0)
        await expect(page.getByTestId('host-key-alert')).toBeVisible()
    })

    test('confirming trusts the presented key and clears the warning', async ({ page }, testInfo) => {
        // This test consumes its fixture, so each project gets its own rekeyed server.
        await gotoServer(page, `rekeyed-${testInfo.project.name}`)

        await page.getByRole('button', { name: 'Accept new key' }).click()
        await page.getByRole('button', { name: 'Yes, trust this key' }).click()

        await expect(page.getByTestId('host-key-alert')).toHaveCount(0)

        await page.reload()

        await expect(page.getByTestId('host-key-alert')).toHaveCount(0)
        await expect(page.getByText(PRESENTED)).toBeVisible()
    })
})

asMember.describe('a changed host key as a member', () => {
    asMember('a member is warned but cannot decide', async ({ page }) => {
        await gotoServer(page, 'rekeyed-server')

        await expect(page.getByTestId('host-key-alert')).toContainText('Server host key has changed')
        await expect(page.getByRole('button', { name: 'Accept new key' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Dismiss for now' })).toHaveCount(0)
    })
})
