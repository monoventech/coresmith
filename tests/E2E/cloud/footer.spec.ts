import { asGuest, expect, main, test } from '../support/fixtures'

test('coresmith cloud has no Made by Monoven mark', async ({ page }) => {
    await page.goto('/monoven/dashboard')
    await expect(main(page).getByRole('heading', { level: 1 })).toBeVisible()

    await expect(page.getByText('Made by Monoven')).toHaveCount(0)
})

asGuest('the coresmith cloud sign in page keeps the copyright line and no Made by Monoven mark', async ({ page }) => {
    await page.goto('/login')

    await expect(page.getByRole('contentinfo')).toContainText('© 2026 Monoven Ltd · coresmith.io · ELv2 licensed')
    await expect(page.getByText('Made by Monoven')).toHaveCount(0)
})
