import { expect, test } from '../support/fixtures'

test.describe('adding a server on coresmith cloud', () => {
    test('a private address is refused before Coresmith Cloud tries to reach it', async ({ page }) => {
        await page.goto('/monoven/servers/create')
        await page.getByLabel('Provider').selectOption('manual')
        await page.getByLabel('Server name').fill('office-nas')
        await page.getByLabel('IP address').fill('192.168.0.248')
        await page.getByRole('button', { name: 'Add server' }).click()

        await expect(
            page
                .getByRole('alert')
                .filter({
                    hasText:
                        "That's a private address, so Coresmith Cloud can't reach it. Use your server's public IP address."
                })
        ).toBeVisible()
        await expect(page.getByLabel('IP address')).toHaveAttribute('aria-invalid', 'true')
        await expect(page.getByLabel('IP address')).toHaveValue('192.168.0.248')
    })
})
