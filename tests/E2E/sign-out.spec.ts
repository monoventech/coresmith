import { asGuest, expect, PASSWORD } from './support/fixtures'

asGuest.use({ trace: 'retain-on-failure' })

asGuest.describe('signing out', () => {
    asGuest('signing out from the account dialog ends the session', async ({ page }) => {
        const remembered = async () =>
            (await page.context().cookies()).filter((cookie) => cookie.name.startsWith('remember_web_')).length

        await page.goto('/login')
        await page.getByLabel('Email address').fill('signout@coresmith.test')
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Sign in' }).click()
        await page.waitForURL(/\/dashboard$/)
        expect(await remembered()).toBe(0)

        await page.getByRole('button', { name: 'Account' }).click()
        await page.getByTestId('sign-out').click()

        await page.waitForURL((url) => !url.pathname.startsWith('/dashboard'))
        expect(await remembered()).toBe(0)
        await page.goto('/dashboard')
        await expect(page).toHaveURL(/\/login$/)
    })
})
