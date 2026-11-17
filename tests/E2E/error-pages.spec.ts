import { asGuest, expect, test } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

const LOCKUP = '0 -87.5 624.4 100'

test("an address that matches nothing shows Coresmith's not found page, whose button opens the fleet", async ({
    page
}) => {
    const response = await page.goto('/no-such-page')

    expect(response?.status()).toBe(404)
    await expect(page.getByRole('link', { name: 'Coresmith home' }).locator('svg')).toHaveAttribute('viewBox', LOCKUP)
    await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible()
    await expect(page.getByTestId('error-status')).toHaveText('Error 404')
    await expect(page).toHaveTitle(/^Page not found/)

    await page.getByRole('link', { name: 'Go to your fleet' }).click()

    await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
})

asGuest('signed out, the not found page leads to signing in', async ({ page }) => {
    expect((await page.goto('/no-such-page'))?.status()).toBe(404)

    await page.getByRole('link', { name: 'Go to your fleet' }).click()

    await expect(page).toHaveURL(/\/login$/)
})
