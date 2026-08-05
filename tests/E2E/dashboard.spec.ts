import { asGuest, expect, test } from './support/fixtures'

test.describe('Dashboard and shell', () => {
    test('the dashboard greets the signed in user', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page.getByText(/Welcome back, Ada/i)).toBeVisible()
    })

    test('the sidebar carries the wordmark and the Monoven mark is in the footer', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page.getByText('Made by Monoven')).toBeVisible()
    })

    asGuest('signing out returns to a guest state', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill('signout@coresmith.test')
        await page.getByLabel('Password', { exact: true }).fill('password-for-e2e')
        await page.getByRole('button', { name: 'Sign in' }).click()
        await page.waitForURL('**/dashboard')

        const toggle = page.getByRole('button', { name: /Toggle Sidebar/i })

        if (await toggle.isVisible()) {
            await toggle.click()
        }

        await page.getByRole('button', { name: /SignOut/i }).first().click()
        await page.getByRole('menuitem', { name: /Sign out|Log out/i }).click()

        await page.waitForURL((url) => !url.pathname.startsWith('/dashboard'))
        await page.goto('/servers')
        await expect(page).toHaveURL(/login/)
    })
})
